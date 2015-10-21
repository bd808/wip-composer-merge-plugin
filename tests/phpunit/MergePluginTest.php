<?php
/**
 * This file is part of the Composer Merge plugin.
 *
 * Copyright (C) 2015 Bryan Davis, Wikimedia Foundation, and contributors
 *
 * This software may be modified and distributed under the terms of the MIT
 * license. See the LICENSE file for details.
 */

namespace Wikimedia\Composer;

use Composer\Config;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Version\VersionParser;
use Wikimedia\Composer\Merge\PluginState;
use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Prophecy\Argument;
use ReflectionProperty;

/**
 * @covers Wikimedia\Composer\Logger
 * @covers Wikimedia\Composer\Merge\ExtraPackage
 * @covers Wikimedia\Composer\Merge\PluginState
 * @covers Wikimedia\Composer\MergePlugin
 */
class MergePluginTest extends \Prophecy\PhpUnit\ProphecyTestCase
{

    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var MergePlugin
     */
    protected $fixture;

    protected function setUp()
    {
        parent::setUp();
        $this->composer = $this->prophesize('Composer\Composer');
        $this->io = $this->prophesize('Composer\IO\IOInterface');

        $this->fixture = new MergePlugin();
        $this->fixture->activate(
            $this->composer->reveal(),
            $this->io->reveal()
        );
    }

    public function testSubscribedEvents()
    {
        $subscriptions = MergePlugin::getSubscribedEvents();
        $this->assertEquals(7, count($subscriptions));
        $this->assertArrayHasKey(
            InstallerEvents::PRE_DEPENDENCIES_SOLVING,
            $subscriptions
        );
        $this->assertArrayHasKey(ScriptEvents::PRE_INSTALL_CMD, $subscriptions);
        $this->assertArrayHasKey(ScriptEvents::PRE_UPDATE_CMD, $subscriptions);
        $this->assertArrayHasKey(ScriptEvents::PRE_AUTOLOAD_DUMP, $subscriptions);
        $this->assertArrayHasKey(PackageEvents::POST_PACKAGE_INSTALL, $subscriptions);
        $this->assertArrayHasKey(ScriptEvents::POST_INSTALL_CMD, $subscriptions);
        $this->assertArrayHasKey(ScriptEvents::POST_UPDATE_CMD, $subscriptions);
    }

    /**
     * Given a root package with no requires
     *   and a composer.local.json with one require
     * When the plugin is run
     * Then the root package should inherit the require
     *   and no modifications should be made by the pre-dependency hook.
     */
    public function testOneMergeNoConflicts()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);

        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(1, count($requires));
                $that->assertArrayHasKey('monolog/monolog', $requires);
            }
        );

        $root->getConflicts()->shouldBeCalled();
        $root->setConflicts(Argument::type('array'))->will(
            function ($args) use ($that) {
                $suggest = $args[0];
                $that->assertEquals(1, count($suggest));
                $that->assertArrayHasKey('conflict/conflict', $suggest);
            }
        );
        $root->getReplaces()->shouldBeCalled();
        $root->setReplaces(Argument::type('array'))->will(
            function ($args) use ($that) {
                $suggest = $args[0];
                $that->assertEquals(1, count($suggest));
                $that->assertArrayHasKey('replace/replace', $suggest);
            }
        );
        $root->getProvides()->shouldBeCalled();
        $root->setProvides(Argument::type('array'))->will(
            function ($args) use ($that) {
                $suggest = $args[0];
                $that->assertEquals(1, count($suggest));
                $that->assertArrayHasKey('provide/provide', $suggest);
            }
        );
        $root->getSuggests()->shouldBeCalled();
        $root->setSuggests(Argument::type('array'))->will(
            function ($args) use ($that) {
                $suggest = $args[0];
                $that->assertEquals(1, count($suggest));
                $that->assertArrayHasKey('suggest/suggest', $suggest);
            }
        );

        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }


    /**
     * Given a root package with requires
     *   and a composer.local.json with requires
     *   and the same package is listed in multiple files
     * When the plugin is run
     * Then the root package should inherit the non-conflicting requires
     *   and conflicting requires should be resolved 'last defined wins'.
     */
    public function testMergeWithReplace()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);

        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(2, count($requires));
                $that->assertArrayHasKey('monolog/monolog', $requires);
                $that->assertEquals(
                    '1.10.0',
                    $requires['monolog/monolog']->getPrettyConstraint()
                );
            }
        );

        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }



    /**
     * Given a root package with no requires
     *   and a composer.local.json with one require, which includes a composer.local.2.json
     *   and a composer.local.2.json with one additional require
     * When the plugin is run
     * Then the root package should inherit both requires
     *   and no modifications should be made by the pre-dependency hook.
     */
    public function testRecursiveIncludes()
    {
        $dir = $this->fixtureDir(__FUNCTION__);

        $root = $this->rootFromJson("{$dir}/composer.json");

        $packages = array();
        $root->setRequires(Argument::type('array'))->will(
            function ($args) use (&$packages) {
                $packages = array_merge($packages, $args[0]);
            }
        );

        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertArrayHasKey('foo', $packages);
        $this->assertArrayHasKey('monolog/monolog', $packages);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * Given a root package with no requires that disables recursion
     *   and a composer.local.json with one require, which includes a composer.local.2.json
     *   and a composer.local.2.json with one additional require
     * When the plugin is run
     * Then the root package should inherit the first require
     *   and no modifications should be made by the pre-dependency hook.
     */
    public function testRecursiveIncludesDisabled()
    {
        $dir = $this->fixtureDir(__FUNCTION__);

        $root = $this->rootFromJson("{$dir}/composer.json");

        $packages = array();
        $root->setRequires(Argument::type('array'))->will(
            function ($args) use (&$packages) {
                $packages = array_merge($packages, $args[0]);
            }
        );

        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertArrayHasKey('foo', $packages);
        $this->assertArrayNotHasKey('monolog/monolog', $packages);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * Given a root package with requires
     *   and a composer.local.json with requires
     *   and the same package is listed in multiple files
     * When the plugin is run
     * Then the root package should inherit the non-conflicting requires
     *   and extra installs should be proposed by the pre-dependency hook.
     */
    public function testOneMergeWithConflicts()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);

        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(2, count($requires));
                $that->assertArrayHasKey(
                    'wikimedia/composer-merge-plugin',
                    $requires
                );
                $that->assertArrayHasKey('monolog/monolog', $requires);
            }
        );

        $root->getDevRequires()->shouldBeCalled();
        $root->setDevRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(2, count($requires));
                $that->assertArrayHasKey('foo', $requires);
                $that->assertArrayHasKey('xyzzy', $requires);
            }
        );

        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(2, count($extraInstalls));
        $this->assertEquals('monolog/monolog', $extraInstalls[0][0]);
        $this->assertEquals('foo', $extraInstalls[1][0]);
    }

    /**
     * Given a root package
     *   and a composer.local.json with a repository
     * When the plugin is run
     * Then the root package should inherit the repository
     */
    public function testMergedRepositories()
    {
        $that = $this;
        $io = $this->io;
        $dir = $this->fixtureDir(__FUNCTION__);

        $repoManager = $this->prophesize(
            'Composer\Repository\RepositoryManager'
        );
        $repoManager->createRepository(
            Argument::type('string'),
            Argument::type('array')
        )->will(
            function ($args) use ($that, $io) {
                $that->assertEquals('vcs', $args[0]);
                $that->assertEquals(
                    'https://github.com/bd808/composer-merge-plugin.git',
                    $args[1]['url']
                );

                return new \Composer\Repository\VcsRepository(
                    $args[1],
                    $io->reveal(),
                    new \Composer\Config()
                );
            }
        );
        $repoManager->addRepository(Argument::any())->will(
            function ($args) use ($that) {
                $that->assertInstanceOf(
                    'Composer\Repository\VcsRepository',
                    $args[0]
                );
            }
        );
        $this->composer->getRepositoryManager()->will(
            function () use ($repoManager) {
                return $repoManager->reveal();
            }
        );

        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(1, count($requires));
                $that->assertArrayHasKey(
                    'wikimedia/composer-merge-plugin',
                    $requires
                );
            }
        );

        $root->getDevRequires()->shouldNotBeCalled();
        $root->setDevRequires()->shouldNotBeCalled();

        $root->setRepositories(Argument::type('array'))->will(
            function ($args) use ($that) {
                $repos = $args[0];
                $that->assertEquals(1, count($repos));
            }
        );

        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * Given a root package
     *   and a composer.local.json with required packages
     * When the plugin is run
     * Then the root package should be updated with stability flags.
     */
    public function testUpdateStabilityFlags()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(4, count($requires));
                $that->assertArrayHasKey('test/foo', $requires);
                $that->assertArrayHasKey('test/bar', $requires);
                $that->assertArrayHasKey('test/baz', $requires);
                $that->assertArrayHasKey('test/xyzzy', $requires);
            }
        );

        $root->getDevRequires()->shouldNotBeCalled();
        $root->setDevRequires(Argument::any())->shouldNotBeCalled();

        $root->getRepositories()->shouldNotBeCalled();
        $root->setRepositories(Argument::any())->shouldNotBeCalled();

        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();
        $root->setSuggests(Argument::any())->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    public function testMergedAutoload()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);
        $root = $this->rootFromJson("{$dir}/composer.json");

        $autoload = array();

        $root->getAutoload()->shouldBeCalled();
        $root->getDevAutoload()->shouldBeCalled();
        $root->getRequires()->shouldNotBeCalled();
        $root->setAutoload(Argument::type('array'))->will(
            function ($args, $root) use (&$autoload) {
                // Can't easily assert directly since there will be multiple
                // calls to this setter to create our final expected state
                $autoload = $args[0];
                // Return the new data for the next call to getAutoLoad()
                $root->getAutoload()->willReturn($args[0]);
            }
        )->shouldBeCalledTimes(2);
        $root->setDevAutoload(Argument::type('array'))->will(
            function ($args) use ($that) {
                $that->assertEquals(
                    array(
                        'psr-4' => array(
                            'Dev\\Kittens\\' => array(
                                'everywhere/',
                                'extensions/Foo/a/',
                                'extensions/Foo/b/',
                            ),
                            'Dev\\Cats\\' => 'extensions/Foo/src/'
                        ),
                        'psr-0' => array(
                            'DevUniqueGlobalClass' => 'extensions/Foo/',
                            '' => 'extensions/Foo/dev/fallback/'
                        ),
                        'files' => array(
                            'extensions/Foo/DevSemanticMediaWiki.php',
                        ),
                        'classmap' => array(
                            'extensions/Foo/DevSemanticMediaWiki.hooks.php',
                            'extensions/Foo/dev/includes/',
                        ),
                    ),
                    $args[0]
                );
            }
        )->shouldBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
        $this->assertEquals(
            array(
                'psr-4' => array(
                    'Kittens\\' => array(
                        'everywhere/',
                        'extensions/Foo/a/',
                        'extensions/Foo/b/',
                    ),
                    'Cats\\' => 'extensions/Foo/src/'
                ),
                'psr-0' => array(
                    'UniqueGlobalClass' => 'extensions/Foo/',
                    '' => 'extensions/Foo/fallback/',
                ),
                'files' => array(
                    'private/bootstrap.php',
                    'extensions/Foo/SemanticMediaWiki.php',
                ),
                'classmap' => array(
                    'extensions/Foo/SemanticMediaWiki.hooks.php',
                    'extensions/Foo/includes/',
                ),
            ),
            $autoload
        );
    }

    /**
     * Given a root package with an extra section
     *   and a composer.local.json with an extra section with no conflicting keys
     * When the plugin is run
     * Then the root package extra section should be extended with content from the local config.
     */
    public function testMergeExtra()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);

        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setExtra(Argument::type('array'))->will(
            function ($args) use ($that) {
                $extra = $args[0];
                $that->assertEquals(2, count($extra));
                $that->assertArrayHasKey('merge-plugin', $extra);
                $that->assertEquals(2, count($extra['merge-plugin']));
                $that->assertArrayHasKey('wibble', $extra);
            }
        )->shouldBeCalled();

        $root->getRequires()->shouldNotBeCalled();
        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * Given a root package with an extra section
     *   and a composer.local.json with an extra section with a conflicting key
     * When the plugin is run
     * Then the version in the root package should win.
     */
    public function testMergeExtraConflict()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);

        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setExtra(Argument::type('array'))->will(
            function ($args) use ($that) {
                $extra = $args[0];
                $that->assertEquals(2, count($extra));
                $that->assertArrayHasKey('merge-plugin', $extra);
                $that->assertArrayHasKey('wibble', $extra);
                $that->assertEquals('wobble', $extra['wibble']);
            }
        )->shouldBeCalled();

        $root->getRequires()->shouldNotBeCalled();
        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * Given a root package with an extra section
     *   and replace mode is active
     *   and a composer.local.json with an extra section with a conflicting key
     * When the plugin is run
     * Then the version in the composer.local.json package should win.
     */
    public function testMergeExtraConflictReplace()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);

        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setExtra(Argument::type('array'))->will(
            function ($args) use ($that) {
                $extra = $args[0];
                $that->assertEquals(2, count($extra));
                $that->assertArrayHasKey('merge-plugin', $extra);
                $that->assertArrayHasKey('wibble', $extra);
                $that->assertEquals('ping', $extra['wibble']);
            }
        )->shouldBeCalled();

        $root->getRequires()->shouldNotBeCalled();
        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * @dataProvider provideOnPostPackageInstall
     * @param string $package Package installed
     * @param bool $first Expected isFirstInstall() value
     * @param bool $locked Expected wasLocked() value
     */
    public function testOnPostPackageInstall($package, $first, $locked)
    {
        $operation = new InstallOperation(
            new Package($package, '1.2.3.4', '1.2.3')
        );
        $event = $this->prophesize('Composer\Installer\PackageEvent');
        $event->getOperation()->willReturn($operation)->shouldBeCalled();

        if ($first) {
            $locker = $this->prophesize('Composer\Package\Locker');
            $locker->isLocked()->willReturn($locked)->shouldBeCalled();
            $this->composer->getLocker()->willReturn($locker->reveal())
                ->shouldBeCalled();
            $event->getComposer()->willReturn($this->composer->reveal())
                ->shouldBeCalled();
        }

        $this->fixture->onPostPackageInstall($event->reveal());
        $this->assertEquals($first, $this->getState()->isFirstInstall());
        $this->assertEquals($locked, $this->getState()->isLocked());
    }

    public function provideOnPostPackageInstall()
    {
        return array(
            array(MergePlugin::PACKAGE_NAME, true, true),
            array(MergePlugin::PACKAGE_NAME, true, false),
            array('foo/bar', false, false),
        );
    }

    /**
     * Given a root package with a branch alias
     * When the plugin is run
     * Then the root package will be unwrapped from the alias.
     */
    public function testHasBranchAlias()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);
        $root = $this->rootFromJson("{$dir}/composer.json");
        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(2, count($requires));
                $that->assertArrayHasKey(
                    'wikimedia/composer-merge-plugin',
                    $requires
                );
                $that->assertArrayHasKey('php', $requires);
            }
        );
        $root = $root->reveal();

        $alias = $this->prophesize('Composer\Package\RootAliasPackage');
        $alias->getAliasOf()->willReturn($root)->shouldBeCalled();

        $this->triggerPlugin($alias->reveal(), $dir);

        $this->assertEquals($root, $this->getState()->getRootPackage());
    }


    /**
     * Given a root package with requires
     *   and a b.json with requires
     *   and an a.json with requires
     *   and a glob of json files with requires
     * When the plugin is run
     * Then the root package should inherit the requires
     *   in the correct order based on inclusion order
     *   for individual files and alpha-numeric sorting
     *   for files included via a glob.
     *
     * @return void
     */
    public function testCorrectMergeOrderOfSpecifiedFilesAndGlobFiles()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);
        $root = $this->rootFromJson("{$dir}/composer.json");

        $expects = array(
            "merge-plugin/b.json",
            "merge-plugin/a.json",
            "merge-plugin/glob-a-glob2.json",
            "merge-plugin/glob-b-glob1.json"
        );

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that, &$expects) {
                $expectedSource = array_shift($expects);
                $that->assertEquals(
                    $expectedSource,
                    $args[0]['wibble/wobble']->getSource()
                );
            }
        );
        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);
    }

    /**
     * Test replace link with self.version as version constraint.
     */
    public function testSelfVersion()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setReplaces(Argument::type('array'))->will(
            function ($args) use ($that) {
                $replace = $args[0];
                $that->assertEquals(3, count($replace));
                $that->assertArrayHasKey('foo/b-sub1', $replace);
                $that->assertArrayHasKey('foo/b-sub2', $replace);

                $that->assertTrue($replace['foo/b'] instanceof Link);
                $that->assertEquals($replace['foo/b']->getConstraint(), $replace['foo/b-sub1']->getConstraint());
                $that->assertEquals($replace['foo/b']->getConstraint(), $replace['foo/b-sub2']->getConstraint());
            }
        );

        $root->getRequires()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);
        $this->assertEquals(0, count($extraInstalls));
    }


    /**
     * @param RootPackage $package
     * @param string $directory Working directory for composer run
     * @return array Constrains added by MergePlugin::onDependencySolve
     */
    protected function triggerPlugin($package, $directory)
    {
        chdir($directory);
        $this->composer->getPackage()->willReturn($package);

        $event = new Event(
            ScriptEvents::PRE_INSTALL_CMD,
            $this->composer->reveal(),
            $this->io->reveal(),
            true, //dev mode
            array(),
            array()
        );
        $this->fixture->onInstallUpdateOrDump($event);

        $requestInstalls = array();
        $request = $this->prophesize('Composer\DependencyResolver\Request');
        $request->install(Argument::any(), Argument::any())->will(
            function ($args) use (&$requestInstalls) {
                $requestInstalls[] = $args;
            }
        );

        $event = new InstallerEvent(
            InstallerEvents::PRE_DEPENDENCIES_SOLVING,
            $this->composer->reveal(),
            $this->io->reveal(),
            true, //dev mode
            $this->prophesize('Composer\DependencyResolver\PolicyInterface')->reveal(),
            $this->prophesize('Composer\DependencyResolver\Pool')->reveal(),
            $this->prophesize('Composer\Repository\CompositeRepository')->reveal(),
            $request->reveal(),
            array()
        );

        $this->fixture->onDependencySolve($event);

        $event = new Event(
            ScriptEvents::PRE_AUTOLOAD_DUMP,
            $this->composer->reveal(),
            $this->io->reveal(),
            true, //dev mode
            array(),
            array( 'optimize' => true )
        );
        $this->fixture->onInstallUpdateOrDump($event);

        $event = new Event(
            ScriptEvents::POST_INSTALL_CMD,
            $this->composer->reveal(),
            $this->io->reveal(),
            true, //dev mode
            array(),
            array()
        );
        $this->fixture->onPostInstallOrUpdate($event);

        return $requestInstalls;
    }

    /**
     * @param string $subdir
     * @return string
     */
    protected function fixtureDir($subdir)
    {
        return __DIR__ . "/fixtures/{$subdir}";
    }

    /**
     * @param string $file
     * @return ObjectProphecy
     */
    protected function rootFromJson($file)
    {
        $that = $this;
        $json = json_decode(file_get_contents($file), true);

        $data = array_merge(
            array(
                'repositories' => array(),
                'require' => array(),
                'require-dev' => array(),
                'conflict' => array(),
                'replace' => array(),
                'provide' => array(),
                'suggest' => array(),
                'extra' => array(),
                'autoload' => array(),
                'autoload-dev' => array(),
            ),
            $json
        );

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $manager = $this->prophesize('Composer\Repository\RepositoryManager');
        $loader = new RootPackageLoader($manager->reveal(), $config);
        $package = $loader->load($data, 'Composer\Package\RootPackage');

        $root = $this->prophesize('Composer\Package\RootPackage');
        $root->getRequires()->willReturn($package->getRequires())->shouldBeCalled();
        $root->getDevRequires()->willReturn($package->getDevRequires());
        $root->getRepositories()->willReturn($package->getRepositories());
        $root->getConflicts()->willReturn($package->getConflicts());
        $root->getReplaces()->willReturn($package->getReplaces());
        $root->getProvides()->willReturn($package->getProvides());
        $root->getSuggests()->willReturn($package->getSuggests());
        $root->getExtra()->willReturn($package->getExtra())->shouldBeCalled();
        $root->getAutoload()->willReturn($package->getAutoload());
        $root->getDevAutoload()->willReturn($package->getDevAutoload());

        $root->getStabilityFlags()->willReturn(array());
        $root->setStabilityFlags(Argument::type('array'))->will(
            function ($args) use ($that) {
                foreach ($args[0] as $key => $value) {
                    $that->assertContains($value, BasePackage::$stabilities);
                }
            }
        );

        return $root;
    }

    /**
     * @return PluginState
     */
    protected function getState()
    {
        $state = new ReflectionProperty(
            get_class($this->fixture),
            'state'
        );
        $state->setAccessible(true);
        return $state->getValue($this->fixture);
    }
}
// vim:sw=4:ts=4:sts=4:et:
