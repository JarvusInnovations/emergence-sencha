<?php

$GLOBALS['Session']->requireAccountLevel('Developer');






/**
 * Configuration
 */
    $defaultExclude = [
        "#/\\.sass-cache(/|$)#"
        ,"#/\\.sencha-backup(/|$)#"
        ,"#/\\.emergence(/|$)#"
    ];







/**
 * Setup environment
 */
    set_time_limit(0);
    Site::$debug = !empty($_REQUEST['debug']);

    if (empty($_GET['dumpWorkspace'])) {
        Benchmark::startLive();
    }








/**
 * Load top-level components
 */
    // get build type
    if (empty($_REQUEST['buildType'])) {
        $buildType = 'production';
    } else {
        $buildType = $_REQUEST['buildType'];
    }
    Benchmark::mark("set buildType: $buildType");


    // get app
    if (empty($_REQUEST['name'])) {
        die('Parameter name required');
    }

    $app = Jarvus\Sencha\App::get($_REQUEST['name']);

    if (!$app) {
        throw new \Exception('Failed to load app');
    }

    Benchmark::mark("loaded app: $app");


    // get framework
    $framework = $app->getFramework();

    if (!$framework) {
        throw new \Exception('Failed to load framework');
    }

    Benchmark::mark("loaded framework: $framework");


    // load CMD
    $cmd = $app->getCmd();

    if (!$cmd) {
        throw new \Exception('Failed to load CMD');
    }

    Benchmark::mark("loaded cmd: $cmd");


    // get app-level classpath
    $classPaths = $app->getClassPaths();
    Benchmark::mark('loaded classPaths:'.PHP_EOL.implode(PHP_EOL, $classPaths));


    // get packages
    $packages = $app->getAllRequiredPackages();
    Benchmark::mark('loaded required packages:'.PHP_EOL.implode(PHP_EOL, $packages));









/**
 * Builds paths and create temporary directories
 */
    // TODO: analyze which are still used:

    // set paths
    $workspacePath = 'sencha-workspace';
    $workspaceConfigPath = "$workspacePath/.sencha";
    $appPath = "$workspacePath/$app";
    $archivePath = "sencha-build/$app/archive";


    // get temporary directory and set paths
    $tmpPath = Emergence_FS::getTmpDir();
    $frameworkTmpPath = "$tmpPath/$framework";
    $workspaceConfigTmpPath = "$tmpPath/.sencha";
    $packagesTmpPath = "$tmpPath/packages";
    $appTmpPath = "$tmpPath/$app";
    $archiveTmpPath = "$appTmpPath/archive";
    $buildTmpPath = "$tmpPath/build/$app/$buildType";
    $libraryTmpPath = "$tmpPath/x";

    Benchmark::mark("created tmp: $tmpPath");


    // get path to framework on disk
    $frameworkPhysicalPath = $framework->getPhysicalPath();
    Benchmark::mark("got physical path to framework: $frameworkPhysicalPath");








/**
 * Copy files into temporary build workspace
 */
    if (stat($frameworkPhysicalPath)['dev'] == stat($tmpPath)['dev']) {
        // copy framework w/ hardlinks if paths are on the same device
        exec("cp -al $frameworkPhysicalPath $frameworkTmpPath");
        Benchmark::mark("copied framework: cp -al $frameworkPhysicalPath $frameworkTmpPath");
    } else {
        // make full copy because hardlines don't work across devices
        exec("cp -a $frameworkPhysicalPath $frameworkTmpPath");
        Benchmark::mark("copied framework: cp -a $frameworkPhysicalPath $frameworkTmpPath");
    }

    // precache and write workspace config
    $cachedFiles = Emergence_FS::cacheTree($workspaceConfigPath);
    Benchmark::mark("precached $cachedFiles files in $workspaceConfigPath");
    $exportResult = Emergence_FS::exportTree($workspaceConfigPath, $workspaceConfigTmpPath);
    Benchmark::mark("exported $workspaceConfigPath to $workspaceConfigTmpPath: ".http_build_query($exportResult));


    // framework -- doesn't need to be written as long as patching ${framework}.dir into sencha.cfg keeps working
#    $framework->writeToDisk("$tmpPath/$framework");
#    Benchmark::mark("wrote $framework to $tmpPath/$framework");


    // precache and write app
    $cachedFiles = Emergence_FS::cacheTree($appPath);
    Benchmark::mark("precached $cachedFiles files in $appPath");
    $exportResult = Emergence_FS::exportTree($appPath, $appTmpPath);
    Benchmark::mark("exported $appPath to $appTmpPath: ".http_build_query($exportResult));


    // write any legacy ${workspace.dir}/x/ classpaths from ext-library
    $libraryClassPaths = [];
    foreach (array_merge([$app], $packages) AS $package) {
        foreach ($package->getClassPaths() AS $classPath) {
            if (strpos($classPath, '${workspace.dir}/x/') === 0) {
                $libraryClassPaths[] = substr($classPath, 19);
            }
        }
    }

    $libraryClassPaths = array_unique($libraryClassPaths);

    foreach ($libraryClassPaths AS $libraryClassPath) {
        $classPathSource = "ext-library/$libraryClassPath";
        $classPathDest = "$libraryTmpPath/$libraryClassPath";

        $cachedFiles = Emergence_FS::cacheTree($classPathSource);
        Benchmark::mark("precached $cachedFiles files in $classPathSource");

        $sourceNode = Site::resolvePath($classPathSource);

        if ($sourceNode instanceof SiteFile) {
            mkdir(dirname($classPathDest), 0777, true);
            copy($sourceNode->RealPath, $classPathDest);
            Benchmark::mark("copied file $classPathSource to $classPathDest");
        } else {
            $exportResult = Emergence_FS::exportTree($classPathSource, $classPathDest);
            Benchmark::mark("exported $classPathSource to $classPathDest: ".http_build_query($exportResult));
        }
    }


    // copy packages to workspace (except framework packages)
    foreach ($packages AS $packageName => $package) {
        if ($package instanceof Jarvus\Sencha\FrameworkPackage) {
            continue;
        }

        $packageTmpPath = "$packagesTmpPath/$packageName";

        $package->writeToDisk($packageTmpPath);
        Benchmark::mark("wrote package $package to $packageTmpPath");
    }


    // write archive
    if (!empty($_GET['archive'])) {
        try {
            $exportResult = Emergence_FS::exportTree($archivePath, $archiveTmpPath);
            Benchmark::mark("exported $archivePath to $archiveTmpPath: ".http_build_query($exportResult));
        } catch (Exception $e) {
            Benchmark::mark("failed to export $archivePath, continueing");
        }
    }









/**
 * Execute build
 */
    // change into app's directory
    chdir($appTmpPath);
    Benchmark::mark("chdir to: $appTmpPath");


    // prepare cmd
    $shellCommand = $cmd->buildShellCommand(
        'ant',
            // preset build directory parameters
            "-Dbuild.dir=$buildTmpPath",
            "-Dapp.output.base=$buildTmpPath", // CMD 5.0.1 needs this set directly too or it gets loaded from app.defaults.json

            // optional closure path
            class_exists('Jarvus\Closure\Compiler') && ($closureJarPath = Jarvus\Closure\Compiler::getJarPath()) ? "-Dbuild.compression.closure.jar=$closureJarPath" : null,

        // ant targets
        $buildType, // buildType target (e.g. "production", "testing") sets up build parameters
        'build'
    );
    Benchmark::mark("running CMD: $shellCommand");


    // optionally dump workspace and exit
    if (!empty($_GET['dumpWorkspace']) && $_GET['dumpWorkspace'] != 'afterBuild') {
        header('Content-Type: application/x-bzip-compressed-tar');
        header('Content-Disposition: attachment; filename="'.$app.'.'.date('Y-m-d').'.tbz"');
        chdir($tmpPath);
        passthru("tar -cjf - ./");
        exec("rm -R $tmpPath");
        exit();
    }


    // execute CMD
    //  - optionally dump workspace and exit
    if (!empty($_GET['dumpWorkspace']) && $_GET['dumpWorkspace'] == 'afterBuild') {
        exec($shellCommand);

        header('Content-Type: application/x-bzip-compressed-tar');
        header('Content-Disposition: attachment; filename="'.$app.'.'.date('Y-m-d').'.tbz"');
        chdir($tmpPath);
        passthru("tar -cjf - ./");
        exec("rm -R $tmpPath");
        exit();
    } else {
        passthru("$shellCommand 2>&1", $cmdStatus);
    }

    Benchmark::mark("CMD finished: exitCode=$cmdStatus");







/**
 * Import build
 */
// import build
if ($cmdStatus == 0) {
    Benchmark::mark("importing $buildTmpPath");

    $importResults = Emergence_FS::importTree($buildTmpPath, "sencha-build/$app/$buildType", [
        'exclude' => $defaultExclude
    ]);
    Benchmark::mark("imported files: ".http_build_query($importResults));

    if ($framework == 'ext') {
        Emergence_FS::importFile("$appTmpPath/bootstrap.js", "$appPath/bootstrap.js");
        Benchmark::mark("imported bootstrap.js");
    }

    if (!empty($_GET['archive'])) {
        Benchmark::mark("importing $archiveTmpPath to $archivePath");

        $importResults = Emergence_FS::importTree($archiveTmpPath, $archivePath);
        Benchmark::mark("imported files: ".http_build_query($importResults));
    }
}






/**
 * Clean up
 */
if (empty($_GET['leaveWorkspace'])) {
    exec("rm -R $tmpPath");
    Benchmark::mark("erased $tmpPath");
}
