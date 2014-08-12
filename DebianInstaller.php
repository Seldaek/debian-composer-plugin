<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace kmiller68;

use Composer\Installer;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Symfony\Component\Process\Process;

class DebianInstaller extends Installer\LibraryInstaller
{

  private $isDebianSystem;
  private $distro;
  private $release;
  private $didUpdate;
  protected $extDirManager;
  protected $extDir;
  protected $extOptions;

  /**
   * {@inheritDoc}
   */
  public function __construct(IOInterface $io, Composer $composer, $filesystem = null)
  {
    parent::__construct($io, $composer, 'extension', $filesystem);

    $this->didUpdate = false;
    $this->extDir = rtrim($composer->getConfig()->get('vendor-dir'), '/').'/ext';
    $this->extOptions = $composer->getConfig()->get('ext-options');
    $this->extDirManager = new ExtensionDirectoryManager($this->filesystem, $this->extDir);

    $this->isDebianSystem = false;
    // check that we are at least on LINUX
    if (strtoupper(php_uname('s')) === 'LINUX') {

      exec('lsb_release -i --short 2> /dev/null', $stdout, $resultDist);
      $this->distro = $stdout[0];

      exec('lsb_release -r --short 2> /dev/null', $stdout, $resultRel);
      $this->release = $stdout[0];

      exec('apt-get --version 2> /dev/null', $stdout, $resultApt);
      exec('dpkg --version 2> /dev/null', $stdout, $resultDpkg);
      $this->isDebianSystem = $resultDist && $resultRel && $resultApt && $resultDpkg;
    }
  }

  /**
   * {@inheritDoc}
   */
  public function suppports($packageType)
  {
    return $this->isDebianSystem && parent::supports($packageType);
  }

  protected function getDebianPackages(PackageInterface $package)
  {
    $extras = $package->getExtra();
    if (empty($extras['apt-get'])) {
      throw new \UnexpectedValueException('Error while installing '
        .$package->getPrettyName()
        .' debian-extension packages should have apt-get defined in their extra key to be installed');
    }

    if (empty($extras['apt-get'][$this->distro])) {
      throw new \UnexpectedValueException('Error while installing '
        .$package->getPrettyName()
        .' debian-extension packages should have the distribution defined in their extra key to be installed');
    }

    if (empty($extras['apt-get'][$this->distro][$this->release])) {
      throw new \UnexpectedValueException('Error while installing '
        .$package->getPrettyName()
        .' debian-extension packages should have the release defined in their extra key to be installed');
    }

    return $extras['apt-get'][$this->distro][$this->release];
  }

  /**
   * {@inheritDoc}
   */
  public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
  {
    parent::install($repo, $package);
    $this->compileExtension($package);
  }

  private function formatNames($names)
  {
    $str = '';
    foreach ($names as $name) {
      $str .= ' '.$name;
    }
    return $str;
  }

  /**
   * {@inheritDoc}
   */
  public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
  {
    $this->cleanExtension($target);
    parent::update($repo, $initial, $target);
    $this->compileExtension($target);
  }

  /**
   * {@inheritDoc}
   */
  public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
  {
    $this->cleanExtension($package);
    $unneeded = $this->extDirManager->removeExtension($package);
    $this->removeUnneeded($unneeded);

    parent::uninstall($repo, $package);
  }

  /**
   * takes a list of debian packages that are no longer needed and asks the user
   * if she/he would like to remove them.
   */
  private function removeUnneeded($unneeded)
  {
    if (count($unneeded) !== 0 && $this->io->isInteractive()) {

      hphpd_break();
      $question = "The following extensions can be removed:".$this->formatNames($unneeded)
        ." would you like to remove them? [y]es/[N]o/[i]nteractive: ";
      $validator = function ($input) {
        switch (strtolower($input)) {
          case "y":
          case "yes":
            return 0;
          case "n":
          case "no":
            return 1;
          case "i":
          case "interactive":
            return 2;
          default:
            throw new \UnexpectedValueException("not a valid response");
        }
      };

      $toRemove = array();
      switch ($this->io->askAndValidate($question, $validator, false, "n")) {
        case 0:
          $toRemove = $unneeded;
          break;
        case 2:
          foreach ($unneeded as $dpkg) {
            $question = "Would you like to remove ".$dpkg."? [y]es/[N]o: ";
            if ($this->io->askConfirmation($question, false)) {
              $toRemove[] = $dpkg;
            }
          }
          break;
      }

      if (count($toRemove) !== 0) {
        passthru("sudo apt-get remove ".$this->formatNames($toRemove));
      }
    }
  }

  private function compileExtension(PackageInterface $package)
  {
      $this->extDirManager->initializeExtDir();

      $packages = $this->getDebianPackages($package);
      $names = $this->formatNames($packages);
      if (!$this->didUpdate) {
        passthru('sudo apt-get update');
        $this->didUpdate = true;
      }
      passthru("sudo apt-get install".$names);

      $flags = (isset($this->extOptions) && isset($this->extOptions[$package->getName()])) ? $this->extOptions[$package->getName()] : '';
      $path = $this->getInstallPath($package);

      if (defined('HHVM_VERSION')) {
          $command = sprintf('hphpize && cmake %s . && make', escapeshellarg($flags));
      } else {
          $command = sprintf('phpize && ./configure %s && make && make install', escapeshellarg($flags));
      }

      $process = new Process($command, $path);
      $io = $this->io;
      $status = $process->run(function ($stream, $data) use ($io) {
          $io->write($data, false);
      });

      if (0 !== $status) {
          throw new \RuntimeException("Could not compile extension ".$package->getName());
      }

      $unneeded = $this->extDirManager->addExtension($package, $packages, $this->getInstallPath($package));
      $this->removeUnneeded($unneeded);
  }

  private function cleanExtension(PackageInterface $package)
  {

      $path = $this->getInstallPath($package);
      $command = 'make clean';

      $process = new Process($command, $path);
      $process->run();
  }
}
