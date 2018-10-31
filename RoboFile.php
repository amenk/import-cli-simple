<?php

/**
 * RoboFile.php
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5
 *
 * @author    Tim Wagner <t.wagner@techdivision.com>
 * @copyright 2016 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/import-cli-simple
 * @link      http://www.techdivision.com
 */

use Lurker\Event\FilesystemEvent;
use Symfony\Component\Finder\Finder;

/**
 * Defines the available build tasks.
 *
 * @author    Tim Wagner <t.wagner@techdivision.com>
 * @copyright 2016 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/import-cli-simple
 * @link      http://www.techdivision.com
 */
class RoboFile extends \Robo\Tasks
{

    /**
     * The build properties.
     *
     * @var array
     */
    protected $properties = array(
        'base.dir' => __DIR__,
        'etc.dir' => __DIR__ . '/etc',
        'src.dir' => __DIR__ . '/src',
        'dist.dir' => __DIR__ . '/dist',
        'vendor.dir' => __DIR__ . '/vendor',
        'target.dir' => __DIR__ . '/target',
        'symfony.dir' => __DIR__ . '/symfony',
        'webapp.name' => 'import-cli-simple',
        'webapp.version' => '3.0.0'
    );

    /**
     * The Magento versions to run the integration tests against.
     *
     * @var array
     */
    protected $magentoVersions = array('community' => array('2.3.0-beta18'));

    /**
     * Run's the composer install command.
     *
     * @return void
     */
    public function composerInstall()
    {
        // optimize autoloader with custom path
        $this->taskComposerInstall()
             ->preferDist()
             ->optimizeAutoloader()
             ->run();
    }

    /**
     * Run's the composer update command.
     *
     * @return void
     */
    public function composerUpdate()
    {
        // optimize autoloader with custom path
        $this->taskComposerUpdate()
             ->preferDist()
             ->optimizeAutoloader()
             ->run();
    }

    /**
     * Clean up the environment for a new build.
     *
     * @return void
     */
    public function clean()
    {
        $this->taskDeleteDir($this->properties['target.dir'])->run();
    }

    /**
     * Prepare's the environment for a new build.
     *
     * @return void
     */
    public function prepare()
    {
        $this->taskFileSystemStack()
             ->mkdir($this->properties['dist.dir'])
             ->mkdir($this->properties['target.dir'])
             ->mkdir(sprintf('%s/reports', $this->properties['target.dir']))
             ->run();
    }

    /**
     * Creates the a PHAR archive from the sources.
     *
     * @return void
     */
    public function createPhar()
    {

        // run the build process
        $this->build();

        // prepare the PHAR archive name
        $archiveName = sprintf(
            '%s/%s.phar',
            $this->properties['target.dir'],
            $this->properties['webapp.name']
        );

        // prepare the target directory
        $targetDir = $this->properties['target.dir'] . DIRECTORY_SEPARATOR . $this->properties['webapp.version'];

        // copy the composer.json file
        $this->taskFilesystemStack()
             ->copy(
                  __DIR__ . DIRECTORY_SEPARATOR . 'composer.json',
                  $targetDir. DIRECTORY_SEPARATOR. 'composer.json'
             )->run();

          // copy the .semver file
          $this->taskFilesystemStack()
               ->copy(
                   __DIR__ . DIRECTORY_SEPARATOR . '.semver',
                   $targetDir. DIRECTORY_SEPARATOR. '.semver'
               )->run();

          // copy the bootstrap.php file
          $this->taskFilesystemStack()
               ->copy(
                  __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php',
                  $targetDir. DIRECTORY_SEPARATOR. 'bootstrap.php'
               )->run();

        // copy the src/etc directory
        $this->taskCopyDir(
                  array(
                      $this->properties['src.dir'] => $targetDir . DIRECTORY_SEPARATOR . 'src'
                  )
               )->run();

        // copy the syfmony directory
        $this->taskCopyDir(
                   array(
                       $this->properties['symfony.dir'] => $targetDir . DIRECTORY_SEPARATOR . 'symfony'
                   )
               )->run();

        // install the composer dependencies
        $this->taskComposerInstall()
            ->dir($targetDir)
            ->noDev()
            ->optimizeAutoloader()
            ->run();

        // prepare the task
        $pharTask = $this->taskPackPhar($archiveName)
            ->compress()
            ->stub('stub.php');

        // load a list with all the source files from the vendor directory
        $finder = Finder::create()->files()
            ->name('*.php')
            ->name('.semver')
            ->name('services.xml')
            ->name('services-1.0.xsd')
            ->name('techdivision-import.json')
            ->in($targetDir)
            ->ignoreDotFiles(false);

        // iterate over the source files of the vendor directory and add them to the PHAR archive
        foreach ($finder as $file) {
            $pharTask->addFile($file->getRelativePathname(), $file->getRealPath());
        }

        // create the PHAR archive
        $pharTask->run();

        // verify PHAR archive is packed correctly
        $this->_exec(sprintf('php %s', $archiveName));

        // prepare the PHAR archive distribution name
        $distArchiveName = sprintf('%s/%s.phar', $this->properties['dist.dir'], $this->properties['webapp.name']);

        // clean up the dist directory
        $this->taskCleanDir($this->properties['dist.dir'])->run();

        // copy the latest PHAR archive to the dist directory
        $this->taskFilesystemStack()->copy($archiveName, $distArchiveName)->run();
    }

    /**
     * Run's the PHPMD.
     *
     * @return void
     */
    public function runMd()
    {

        // run the mess detector
        $this->_exec(
            sprintf(
                '%s/bin/phpmd %s xml phpmd.xml --reportfile %s/reports/pmd.xml --ignore-violations-on-exit',
                $this->properties['vendor.dir'],
                $this->properties['src.dir'],
                $this->properties['target.dir']
            )
        );
    }

    /**
     * Run's the PHPCPD.
     *
     * @return void
     */
    public function runCpd()
    {

        // run the copy past detector
        $this->_exec(
            sprintf(
                '%s/bin/phpcpd %s --log-pmd %s/reports/pmd-cpd.xml --names-exclude *Factory.php',
                $this->properties['vendor.dir'],
                $this->properties['src.dir'],
                $this->properties['target.dir']
            )
        );
    }

    /**
     * Run's the PHPCodeSniffer.
     *
     * @return void
     */
    public function runCs()
    {

        // run the code sniffer
        $this->_exec(
            sprintf(
                '%s/bin/phpcs -n --report-full --extensions=php --standard=phpcs.xml --report-checkstyle=%s/reports/phpcs.xml %s',
                $this->properties['vendor.dir'],
                $this->properties['target.dir'],
                $this->properties['src.dir']
            )
        );
    }

    /**
     * Run's the PHPUnit testsuite.
     *
     * @return void
     */
    public function runTestsUnit()
    {

        // run PHPUnit
        $this->taskPHPUnit(sprintf('%s/bin/phpunit --testsuite "techdivision/import-cli-simple PHPUnit testsuite"', $this->properties['vendor.dir']))
             ->configFile('phpunit.xml')
             ->run();
    }

    /**
     * Run's the integration testsuite.
     *
     * This task uses the Magento 2 docker image generator from https://github.com/techdivision/magento2-docker-imgen. To execute
     * this task, it is necessary that you set your Magent credentials for http://repo.magento.com on the commandline like
     *
     * ```php
     * MAGENTO_REPO_USERNAME=##YOUR_PUBLIC_ACCESS_KEY## MAGENTO_REPO_PASSWORD=##YOUR_PRIVATE_ACCESS_KEY## vendor/bin/robo run:tests-integration
     * ```
     *
     * @return void
     */
    public function runTestsIntegration()
    {

        // prepare the filesystem
        $this->prepare();

        // clone the repository for the Magento 2 docker image generator
        $this->taskGitStack()
             ->cloneRepo('https://github.com/techdivision/magento2-docker-imgen.git', $targetDir = sprintf('%s/magento2-docker-imgen', $this->properties['target.dir']))
             ->run();

        // run the integration test suite for the configured editions/versions
        foreach ($this->magentoVersions as $edition => $versions) {
            foreach ($versions as $version) {
                // strip the dots from the version number
                $strippedVersion = str_replace('.', '', $version);

                // initialize the name for the docker container
                $containerName = sprintf('magento_%s_%s', $edition, $strippedVersion);

                // initialize the name for the test domain
                $domainName = sprintf('magento-%s-%s.test', $edition, $strippedVersion);

                // try to load username/password for the Magento 2 repository from the environment variables
                $repoUsername = getenv('MAGENTO_REPO_USERNAME');
                $repoPassword = getenv('MAGENTO_REPO_PASSWORD');

                // initialize the hash value for an existing image
                $hash = null;

                // query whether or not the image already exists
                exec(sprintf('docker images -q magento/%s:%s', $edition, $version), $hash);

                // create the image, if not yet available
                if (empty($hash)) {
                    // build the Magento 2 instance
                    $this->taskExec(
                        str_replace(
                            array('{edition}', '{version}', '{repo-username}', '{repo-password}'),
                            array($edition, $version, $repoUsername, $repoPassword),
                            'docker build \
                                   --build-arg MAGENTO_REPO_USERNAME={repo-username} \
                                   --build-arg MAGENTO_REPO_PASSWORD={repo-password} \
                                   --build-arg MAGENTO_INSTALL_EDITION={edition} \
                                   --build-arg MAGENTO_INSTALL_VERSION={version} \
                                    -t magento/{edition}:{version} .'
                            )
                        )
                        ->dir($targetDir)
                        ->run();
                }

                // run the Magento 2 instance
                $this->taskExec(
                          str_replace(
                              array('{edition}', '{version}', '{stripped-version}', '{container-name}', '{domain-name}'),
                              array($edition, $version, $strippedVersion, $containerName, $domainName),
                              'docker run --rm -d \
                                   --name {container-name} \
                                   -p 127.0.0.1:80:80 \
                                   -p 127.0.0.1:443:443 \
                                   -p 127.0.0.1:3306:3306 \
                                   -e MAGENTO_BASE_URL={domain-name} magento/{edition}:{version}'
                         )
                     )
                     ->run();

                // initialize the variables to query whether or not the docker container has been started successfully
                $counter = 0;
                $magentoNotAvailable = true;

                do {
                    // reset the result of the CURL request
                    $res = null;

                    // query whether or not the image already exists
                    exec(str_replace(array('{domain-name}'), array($domainName), 'curl --resolve {domain-name}:80:127.0.0.1 http://{domain-name}/magento_version'), $res);

                    // query whether or not the Docker has been started
                    foreach ($res as $val) {
                        if (strstr($val, 'Magento/')) {
                            $magentoNotAvailable = false;
                        }
                    }

                    // raise the counter
                    $counter++;

                    // sleep while the docker container is not available
                    if ($magentoNotAvailable === true) {
                        sleep(1);
                    }

                } while ($magentoNotAvailable && $counter < 30);

                // grant the privilieges to connection from outsite the container
                $this->taskDockerExec($containerName)
                     ->interactive()
                     ->exec('mysql -uroot -pappserver.i0 -e \'GRANT ALL ON *.* TO "magento"@"%" IDENTIFIED BY "magento"\'')
                     ->run();

                // flush the privileges
                $this->taskDockerExec($containerName)
                     ->interactive()
                     ->exec('mysql -uroot -pappserver.i0 -e "FLUSH PRIVILEGES"')
                     ->run();

                // run the integration testsuite
                $this->taskPHPUnit(
                        sprintf(
                            '%s/bin/phpunit --testsuite "techdivision/import-cli-simple PHPUnit integration testsuite"',
                            $this->properties['vendor.dir']
                        )
                     )
                     ->configFile('phpunit.xml')
                     ->run();

                 // stop the containers
                 $this->taskExec(sprintf('docker stop %s', $containerName))->run();
            }
        }
    }

    /**
     * Raising the semver version number.
     *
     * @return void
     */
    public function semver()
    {
        $this->taskSemVer('.semver')
             ->prerelease('beta')
             ->run();
    }

    /**
     * The complete build process.
     *
     * @return void
     */
    public function build()
    {
        $this->clean();
        $this->prepare();
        $this->runCs();
        $this->runCpd();
        $this->runMd();
        $this->runTestsUnit();
    }
}
