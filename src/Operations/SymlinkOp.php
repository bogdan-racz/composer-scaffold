<?php

declare(strict_types = 1);

namespace Grasmash\ComposerScaffold\Operations;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Grasmash\ComposerScaffold\ScaffoldFileInfo;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Scaffold operation to symlink from source to destination.
 */
class SymlinkOp extends ReplaceOp {

  /**
   * Process the replace operation. This could be a copy or a symlink.
   */
  public function placeScaffold(ScaffoldFileInfo $scaffold_file, IOInterface $io, array $options) {
    $interpolator = $scaffold_file->getInterpolator();
    $source_path = $this->getSource()->fullPath();
    $destination_path = $scaffold_file->getDestinationFullPath();

    try {
      $fs = new Filesystem();
      $fs->relativeSymlink($source_path, $destination_path);
    }
    catch (\Exception $e) {
      throw new \Exception($interpolator->interpolate("Could not symlink source file <info>[src-rel-path]</info> to <info>[dest-rel-path]</info>! ", $this->interpolationData()), 1, $e);
    }

    $io->write($interpolator->interpolate("  - symlink source file <info>[src-rel-path]</info> to <info>[dest-rel-path]</info>", $this->interpolationData()));
  }

}
