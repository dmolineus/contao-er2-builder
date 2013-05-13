<?php

namespace ER2Builder\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class BuildCommand extends Command
{
	protected function configure()
	{
		$this
			->setName('build')
			->setDescription('Build an ER2 package from a composer designed project.')
			->addOption('zip', 'Z', InputOption::VALUE_NONE, 'Create a zip archive (enabled by default, only present for sanity).')
			->addOption('dir', 'D', InputOption::VALUE_NONE, 'Create a directory instead of an archive.')
			->addOption('branch', 'b', InputOption::VALUE_REQUIRED, 'The branch to use.', 'master')
			->addArgument('uri', InputArgument::REQUIRED, 'URI to the git repository.')
			->addArgument('output', InputArgument::OPTIONAL, 'The output path.', 'package.zip');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$createZip = $input->getOption('zip');
		$createDir = $input->getOption('dir');
		$branch = $input->getOption('branch');

		$uri = $input->getArgument('uri');
		$out = $input->getArgument('output');

		$dependencies = array();

		$fs = new Filesystem();

		$tempRepository = tempnam(sys_get_temp_dir(), 'er2_repository_');
		unlink($tempRepository);
		mkdir($tempRepository);

		$tempPackage = tempnam(sys_get_temp_dir(), 'er2_package_');
		unlink($tempPackage);
		mkdir($tempPackage);

		$writethru = function ($type, $buffer) use ($output) {
			$output->write($buffer);
		};

		if (!file_exists(__DIR__ . '/composer.phar')) {
			$output->writeln('  - <info>Install local copy of composer</info>');
			$process = new Process('curl -sS https://getcomposer.org/installer | php', __DIR__);
			$process->run($writethru);
			if (!$process->isSuccessful()) {
				throw new \RuntimeException($process->getErrorOutput());
			}
		}

		$output->writeln('  - <info>Clone project</info>');
		$process = new Process('git clone --branch ' . escapeshellarg($branch) . ' -- ' . escapeshellarg($uri) . ' ' . escapeshellarg($tempRepository));
		$process->run($writethru);
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}


		$output->writeln('  - <info>Validate project</info>');
		if (!file_exists($tempRepository . '/composer.json')) {
			throw new \RuntimeException('Project ' . $uri . ' does not seems to be a composer project.');
		}
		$config = json_decode(file_get_contents($tempRepository . '/composer.json'), true);
		if (!array_key_exists('type', $config) || $config['type'] != 'contao-module') {
			throw new \RuntimeException('Project ' . $uri . ' does not seems to be a contao module.');
		}
		$symlinks = array();
		$runonce = array();
		$modulePath = false;
		if (array_key_exists('extra', $config) &&
			array_key_exists('contao', $config['extra'])) {
			if (array_key_exists('symlinks', $config['extra']['contao'])) {
				$symlinks = $config['extra']['contao']['symlinks'];
			}
			if (array_key_exists('runonce', $config['extra']['contao'])) {
				$runonce = $config['extra']['contao']['runonce'];
			}
		}
		foreach ($symlinks as $source => $target) {
			if (preg_match('#^system/modules/[^/]+#', $target)) {
				$modulePath = $target;
				break;
			}
		}
		if (!$modulePath) {
			$modulePath = 'system/modules/' . preg_replace('#^.*/(.*)$#', '$1', $config['name']);
			$output->writeln('  * <comment>Module path not found, guessing the module path from name: ' . $modulePath . '</comment>');
		}


		if (array_key_exists('require', $config)) {
			$output->writeln('  - <info>Remove unneeded dependencies</info>');
			foreach ($config['require'] as $package => $version) {
				if ($package == 'contao/core' ||
					in_array($this->getPackageType($package, $version), array('legacy-contao-module', 'contao-module'))
				) {
					$dependencies[$package] = $version;
					unset($config['require'][$package]);
				}
			}
			file_put_contents($tempRepository . '/composer.json', json_encode($config, JSON_PRETTY_PRINT));
		}


		$output->writeln('  - <info>Install dependencies</info>');
		$process = new Process('php ' . escapeshellarg(__DIR__ . '/composer.phar') . ' install --no-dev', $tempRepository);
		$process->run($writethru);
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}


		$output->writeln('  - <info>Copy files into package</info>');
		$fs->mkdir($tempPackage . '/' . $modulePath . '/config');
		foreach ($symlinks as $source => $target) {
			$this->copy($tempRepository . '/' . $source, $tempPackage . '/' . $target, $fs);
		}
		foreach (array_values($runonce) as $index => $file) {
			$fs->copy($tempRepository . '/' . $file, $tempPackage . '/' . $modulePath . '/config/runonce_' . $index . '.php');
		}
		if (count($runonce)) {
			$class = uniqid('runonce_', true);
			file_put_contents(
				$tempPackage . '/' . $modulePath . '/config/runonce.php',
				<<<EOF
<?php

class $class extends System
{
	public function __construct()
	{
		parent::__construct();
	}

	public function run()
	{
		for (\$i=0; file_exists(__DIR__ . '/runonce_' . \$i . '.php'); \$i++) {
			try {
				require_once(__DIR__ . '/runonce_' . \$i . '.php');
			}
			catch (\Exception \$e) {
				// first trigger an error to write this into the log file
				trigger_error(
					\$e->getMessage() . "\n" . \$e->getTraceAsString(),
					E_USER_ERROR
				);
				// now log into the system log
				\$this->log(
					\$e->getMessage() . "\n" . \$e->getTraceAsString(),
					'RunonceExecutor run()',
					'ERROR'
				);
			}
		}
	}
}

\$executor = new $class();
\$executor->run();

EOF
			);
		}
		$fs->mkdir($tempPackage . '/' . $modulePath . '/classes');
		if (array_key_exists('autoload', $config)) {
			if (array_key_exists('psr-0', $config['autoload'])) {
				foreach ($config['autoload']['psr-0'] as $source) {
					$this->copy($tempRepository . '/' . $source, $tempPackage . '/' . $modulePath . '/classes/' . $source, $fs);
				}
			}
		}
		$this->copy($tempRepository . '/vendor', $tempPackage . '/' . $modulePath . '/classes/vendor', $fs);
		if (file_exists($tempPackage . '/' . $modulePath . '/config/autoload.php')) {
			$autoload = file_get_contents($tempPackage . '/' . $modulePath . '/config/autoload.php');
			$autoload = preg_replace('#\?>\s*$#', '', $autoload);
		}
		else {
			$autoload = <<<EOF
<?php

EOF
			;
		}

			$autoload .= <<<EOF

require_once(dirname(__DIR__) . '/classes/vendor/autoload.php');

EOF
		;
		file_put_contents(
			$tempPackage . '/' . $modulePath . '/config/autoload.php',
			$autoload
		);


		if (count($dependencies)) {
			$output->writeln('  - <info>Remember to define the dependencies</info>');
			foreach ($dependencies as $package => $version) {
				$output->writeln('  * <comment>' . $package . ' ' . $version . '</comment>');
			}
		}


		if ($createDir) {
			$output->writeln('  - <info>Create package</info>');
			$this->copy($tempPackage, $out, $fs);
		}
		else {
			$output->writeln('  - <info>Create package archive</info>');
			$zip = new \ZipArchive();
			$zip->open($out, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
			$this->addToZipArchive($zip, $tempPackage, '');
			$zip->close();
		}

		$output->writeln('  - <info>Cleanup</info>');
		$fs->remove($tempRepository);
		$fs->remove($tempPackage);
	}

	protected function copy($source, $target, Filesystem $fs)
	{
		if (is_dir($source)) {
			$fs->mkdir($target);
			$iterator = new \FilesystemIterator($source, \FilesystemIterator::CURRENT_AS_PATHNAME);
			foreach ($iterator as $item) {
				$this->copy($item, $target . '/' . basename($item), $fs);
			}
		}
		else {
			$fs->copy($source, $target);
		}
	}

	protected function getPackageType($package, $version)
	{
		$process = new Process('php ' . escapeshellarg(__DIR__ . '/composer.phar') . ' show ' . escapeshellarg($package) . ' ' . escapeshellarg($version));
		$process->run();
		if (!$process->isSuccessful()) {
			throw new \RuntimeException($process->getErrorOutput());
		}
		$details = $process->getOutput();
		foreach (explode("\n", $details) as $line) {
			$parts = explode(':', $line);
			$parts = array_map('trim', $parts);
			if ($parts[0] == 'type') {
				return $parts[1];
			}
		}
		return 'library';
	}

	protected function addToZipArchive(\ZipArchive $zip, $source, $target)
	{
		if (is_dir($source)) {
			if ($target) {
				$zip->addEmptyDir($target);
			}
			$iterator = new \FilesystemIterator($source, \FilesystemIterator::CURRENT_AS_PATHNAME);
			foreach ($iterator as $item) {
				$this->addToZipArchive($zip, $item, ($target ? $target . '/' : '') . basename($item));
			}
		}
		else {
			$zip->addFile($source, $target);
		}
	}
}