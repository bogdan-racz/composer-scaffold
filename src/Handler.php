<?php

declare(strict_types = 1);

namespace Grasmash\ComposerScaffold;

use Composer\Package\PackageInterface;
use Composer\Script\Event;
use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Core class of the plugin, contains all logic which files should be fetched.
 */
class Handler {

  const PRE_COMPOSER_SCAFFOLD_CMD = 'pre-composer-scaffold-cmd';
  const POST_COMPOSER_SCAFFOLD_CMD = 'post-composer-scaffold-cmd';

  /**
   * The Composer service.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * Composer's I/O service.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * Handler constructor.
   *
   * @param \Composer\Composer $composer
   *   The Composer service.
   * @param \Composer\IO\IOInterface $io
   *   The Composer I/O service.
   */
  public function __construct(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * Post install command event to execute the scaffolding.
   *
   * @param \Composer\Script\Event $event
   *   The Composer event.
   */
  public function onPostCmdEvent(Event $event) {
    $this->scaffold();
  }

  /**
   * Gets the array of file mappings provided by a given package.
   *
   * @param \Composer\Package\PackageInterface $package
   *   The Composer package from which to get the file mappings.
   *
   * @return array
   *   An associative array of file mappings, keyed by relative source file
   *   path. Items that are specified as 'false' are converted to an empty string.
   *   For example:
   *   [
   *     'path/to/source/file' => 'path/to/destination',
   *     'path/to/source/file' => '',
   *   ]
   */
  public function getPackageFileMappings(PackageInterface $package) : array {
    $package_extra = $package->getExtra();

    if (isset($package_extra['composer-scaffold']['file-mapping'])) {
      $package_file_mappings = $package_extra['composer-scaffold']['file-mapping'];
      return $this->validatePackageFileMappings($package_file_mappings);
    }
    else {
      if (!isset($package_extra['composer-scaffold']['allowed-packages'])) {
        $this->io->writeError("The allowed package {$package->getName()} does not provide a file mapping for Composer Scaffold.");
      }
      return [];
    }
  }

  /**
   * Validate the package file mappings.
   *
   * Throw an exception if there are invalid values, and normalize the value otherwise.
   *
   * @param string[] $package_file_mappings
   *   An array of destination => source scaffold file mappings.
   *
   * @return string[]
   *   The provided $package_file_mappings with its array values normalized.
   */
  protected function validatePackageFileMappings(array $package_file_mappings) {
    $result = [];

    foreach ($package_file_mappings as $key => $value) {
      $result[$key] = $this->normalizeMapping($value);
    }

    return $result;
  }

  /**
   * Normalize a value from the package file mappings.
   *
   * Currently, the valid values are:
   *   (bool) FALSE: Remove the scaffold file rather than scaffold it.
   *   'relative/path': Path to the file to place, relative to the package root.
   *
   * In the future, we want to normalize to:
   *   [
   *      'path' => 'relative/path',
   *      'mode' => 'replace/prepend/append/remove'
   *   ]
   *
   * Note that 'replace' might copy or might make a symlink, depending on
   * settings. The symlink setting is ignored for all modes other than replace.
   *
   * @param string|bool $value
   *   The value to normalize.
   *
   * @return string
   *   The normalized value.
   */
  protected function normalizeMapping($value) {
    if (is_bool($value)) {
      if (!$value) {
        return '';
      }
      throw new \Exception("File mapping $key cannot be given the value 'true'.");
    }
    if (empty($value)) {
      throw new \Exception("File mapping $key cannot be an empty string.");
    }
    return $value;
  }

  /**
   * Copies all scaffold files from source to destination.
   */
  public function scaffold() {
    // Call any pre-scaffold scripts that may be defined.
    $dispatcher = new EventDispatcher($this->composer, $this->io);
    $dispatcher->dispatch(self::PRE_COMPOSER_SCAFFOLD_CMD);

    $locationReplacements = $this->getLocationReplacements();

    // Get the list of allowed packages, and then use it to recursively
    // to fetch the list of file mappings, and normalize them.
    $allowedPackages = $this->getAllowedPackages();
    $file_mappings = $this->getFileMappingsFromPackages($allowedPackages);

    // Collect the list of file mappings, and determine which take priority.
    $scaffoldCollection = new ScaffoldCollection($this->composer);
    $scaffoldCollection->coalateScaffoldFiles($file_mappings, $locationReplacements);

    // Write the collected scaffold files to the designated location on disk.
    $this->scaffoldPackageFiles($scaffoldCollection);

    // Generate an autoload file in the document root that includes
    // the autoload.php file in the vendor directory, wherever that is.
    // Drupal requires this in order to easily locate relocated vendor dirs.
    $this->generateAutoload();

    // Call post-scaffold scripts.
    $dispatcher->dispatch(self::POST_COMPOSER_SCAFFOLD_CMD);
  }

  /**
   * Generate the autoload file at the project root.
   *
   * Include the autoload file that Composer generated.
   */
  public function generateAutoload() {
    $vendorPath = $this->getVendorPath();
    $webroot = $this->getWebRoot();

    // Calculate the relative path from the webroot (location of the project
    // autoload.php) to the vendor directory.
    $fs = new SymfonyFilesystem();
    $relativeVendorPath = $fs->makePathRelative($vendorPath, realpath($webroot));

    $fs->dumpFile($webroot . "/autoload.php", $this->autoLoadContents($relativeVendorPath));
  }

  /**
   * Build the contents of the autoload file.
   *
   * @return string
   *   Return the contents for the autoload.php.
   */
  protected function autoLoadContents(string $relativeVendorPath) : string {
    $relativeVendorPath = rtrim($relativeVendorPath, '/');

    return <<<EOF
<?php

/**
 * @file
 * Includes the autoloader created by Composer.
 *
 * This file was generated by composer-scaffold.
 *.
 * @see composer.json
 * @see index.php
 * @see core/install.php
 * @see core/rebuild.php
 * @see core/modules/statistics/statistics.php
 */

return require __DIR__ . '/$relativeVendorPath/autoload.php';

EOF;
  }

  /**
   * Get the path to the 'vendor' directory.
   *
   * @return string
   *   The file path of the vendor directory.
   */
  public function getVendorPath() {
    $vendorDir = $this->composer->getConfig()->get('vendor-dir');
    $filesystem = new Filesystem();
    $filesystem->ensureDirectoryExists($vendorDir);
    return $filesystem->normalizePath(realpath($vendorDir));
  }

  /**
   * Retrieve the path to the web root.
   *
   * @return string
   *   The file path of the web root.
   *
   * @throws \Exception
   */
  public function getWebRoot() {
    $options = $this->getOptions();
    // @todo Allow packages to set web root location?
    if (empty($options['locations']['web-root'])) {
      throw new \Exception("The extra.composer-scaffold.location.web-root is not set in composer.json.");
    }
    return $options['locations']['web-root'];
  }

  /**
   * Retrieve a package from the current composer process.
   *
   * @param string $name
   *   Name of the package to get from the current composer installation.
   *
   * @return \Composer\Package\PackageInterface|null
   *   The Composer package.
   */
  protected function getPackage(string $name) {
    $package = $this->composer->getRepositoryManager()->getLocalRepository()->findPackage($name, '*');
    if (is_null($package)) {
      throw new \Exception("<comment>Composer Scaffold could not find installed package `$name`.</comment>");
    }

    return $package;
  }

  /**
   * Retrieve options from optional "extra" configuration.
   *
   * @return array
   *   The composer-scaffold configuration array.
   */
  protected function getOptions() : array {
    return $this->getOptionsForPackage($this->composer->getPackage());
  }

  /**
   * Retrieve options from optional "extra" configuration for a package.
   *
   * @param \Composer\Package\PackageInterface $package
   *   The package to pull configuration options from.
   *
   * @return array
   *   The composer-scaffold configuration array for the given package.
   */
  protected function getOptionsForPackage(PackageInterface $package) : array {
    $extra = $package->getExtra() + ['composer-scaffold' => []];

    return $extra['composer-scaffold'] + [
      "allowed-packages" => [],
      "locations" => [],
      "symlink" => FALSE,
      "file-mapping" => [],
    ];
  }

  /**
   * GetLocationReplacements creates an interpolator for the 'locations' element.
   *
   * The interpolator returned will replace a path string with the tokens
   * defined in the 'locations' element.
   *
   * @return Interpolator
   *   Object that will do replacements in a string using tokens in 'locations' element.
   */
  public function getLocationReplacements() {
    $interpolator = new Interpolator();

    $fs = new Filesystem();
    $options = $this->getOptions();
    $locations = $options['locations'] + ['web_root' => './'];
    $locations = array_map(
      function ($location) use ($fs) {
        $fs->ensureDirectoryExists($location);
        $location = realpath($location);
        return $location;
      },
      $locations
    );

    return $interpolator->setData($locations);
  }

  /**
   * Gets a consolidated list of file mappings from all allowed packages.
   *
   * @param \Composer\Package\Package[] $allowed_packages
   *   A multidimensional array of file mappings, as returned by
   *   self::getAllowedPackages().
   *
   * @return array
   *   An multidimensional array of file mappings, which looks like this:
   *   [
   *     'drupal/core' => [
   *       'path/to/source/file' => 'path/to/destination',
   *       'path/to/source/file' => false,
   *     ],
   *     'some/package' => [
   *       'path/to/source/file' => 'path/to/destination',
   *     ],
   *   ]
   */
  protected function getFileMappingsFromPackages(array $allowed_packages) : array {
    $file_mappings = [];
    foreach ($allowed_packages as $package_name => $package) {
      $package_file_mappings = $this->getPackageFileMappings($package);
      $file_mappings[$package_name] = $package_file_mappings;
    }
    return $file_mappings;
  }

  /**
   * Gets a list of all packages that are allowed to copy scaffold files.
   *
   * Configuration for packages specified later will override configuration
   * specified by packages listed earlier. In other words, the last listed
   * package has the highest priority. The root package will always be returned
   * at the end of the list.
   *
   * @return \Composer\Package\PackageInterface[]
   *   An array of allowed Composer packages.
   */
  protected function getAllowedPackages(): array {
    $options = $this->getOptions() + [
      'allowed-packages' => [],
    ];
    $allowed_packages = $this->recursiveGetAllowedPackages($options['allowed-packages']);

    // Add root package at the end so that it overrides all the preceding
    // package.
    $root_package = $this->composer->getPackage();
    $allowed_packages[$root_package->getName()] = $root_package;

    return $allowed_packages;
  }

  /**
   * Description.
   *
   * @param string[] $packages_to_allow
   *   List of package names.
   * @param array $allowed_packages
   *   Mapping of package names to PackageInterface.
   *
   * @return array
   *   Mapping of package names to PackageInterface in priority order.
   */
  protected function recursiveGetAllowedPackages(array $packages_to_allow, array $allowed_packages = []) {
    $root_package = $this->composer->getPackage();
    foreach ($packages_to_allow as $name) {
      if ($root_package->getName() === $name) {
        continue;
      }
      $package = $this->getPackage($name);
      if ($package instanceof PackageInterface && !array_key_exists($name, $allowed_packages)) {
        $allowed_packages[$name] = $package;

        $packageOptions = $this->getOptionsForPackage($package);
        $allowed_packages = $this->recursiveGetAllowedPackages($packageOptions['allowed-packages'], $allowed_packages);
      }
    }
    return $allowed_packages;
  }

  /**
   * Scaffolds the files in our scaffold collection, package-by-package.
   *
   * @param ScaffoldCollection $scaffoldCollection
   *   The scaffold files to process.
   */
  protected function scaffoldPackageFiles(ScaffoldCollection $scaffoldCollection) {
    $options = $this->getOptions();

    // We could simply scaffold all of the files from $list_of_scaffold_files,
    // which contain only the list of files to be processed. We iterate over
    // $resolved_file_mappings instead so that we can print out all of the
    // scaffold files grouped by the package that provided them, including
    // those not being scaffolded (because they were overridden or removed
    // by some later package).
    foreach ($scaffoldCollection->fileMappings() as $package_name => $package_scaffold_files) {
      $this->io->write("Scaffolding files for <comment>$package_name</comment>:");
      foreach ($package_scaffold_files as $dest_rel_path => $scaffold_file) {
        $overriding_package = $scaffoldCollection->findProvidingPackage($scaffold_file);
        if ($scaffold_file->overridden($overriding_package)) {
          $this->io->write($scaffold_file->interpolate("  - <info>[dest-rel-path]</info> overridden in <comment>$overriding_package</comment>"));
        }
        else {
          $scaffold_file->process($this->io, $options);
        }
      }
    }
  }

}
