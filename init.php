<?php
$initConfig = parse_ini_file(__DIR__ . '/inittpl/config.ini', true);
//删除多余项目前缀
$appName = $initConfig['base']['app_name'];
$nginx = $initConfig['base']['nginx'];
$php = $initConfig['base']['php'];
$supervisorCtl = $initConfig['base']['supervisorctl'];
//项目目录
$target = rtrim($initConfig['base']['path'], '/');
//nginx配置文件目录
$nginxPath = $target . '/nginx/conf.d';
//supervisor配置文件目录
$supervisorPath = $target . '/supervisor/conf.d';


/**
 * 创建配置文件
 * @param $path
 */
$mkdirFuc = function ($path) {
    if (is_dir($path)) {
        return;
    }
    mkdir($path, 0777, true);
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
};

/**
 * 解析配置文件
 * @param $stream
 * @param $port
 * @return array[]
 */
$portFunc = function ($stream, $port) use ($supervisorPath, $appName) {
    $port = explode(',', $port);
    if (!count($port) || count($port) % 2 != 0) {
        echo "请配置大于0且为偶数的hyperf端口号!!!!" . PHP_EOL;
        exit(2);
    }
    $stream = str_replace('up_', '', $stream);
    $supervisorConf = glob($supervisorPath . '/' . $appName . '_*.conf');
    $result = [];
    for ($i = 0; $i < count($port); $i += 2) {
        $config = ['has' => $port[$i + 1], 'no' => $port[$i]];
        $path0 = $supervisorPath . '/' . $appName . '_' . $stream . '_' . $port[$i] . '.conf';
        $path1 = $supervisorPath . '/' . $appName . '_' . $stream . '_' . $port[$i + 1] . '.conf';
        if (in_array($path0, $supervisorConf)) {
            $config['has'] = $port[$i];
            $config['no'] = $port[$i + 1];
        }
        if (in_array($path1, $supervisorConf)) {
            $config['has'] = $port[$i + 1];
            $config['no'] = $port[$i];
        }
        $result[] = $config;
    }
    return [$stream => $result];
};

/**
 * 创建hyperf项目
 * @param $streamConfig
 */
$createHyperf = function ($streamConfig) use ($target, $appName) {
    foreach ($streamConfig as $stream => $oneItem) {
        foreach ($oneItem as $port) {
            $targetPath = $target . '/' . $appName . '_' . $stream . '_' . $port['no'] . '/';
            if (!is_dir($targetPath)) {
                mkdir($targetPath, 0777, true);
            }
            shell_exec('cp -rf ' . __DIR__ . '/../* ' . $targetPath);
            $configFile = __DIR__ . '/inittpl/up_' . $stream . '.conf';
            if (!is_file($configFile)) {
                echo '请创建inittpl/up_' . $stream . '配置文件' . PHP_EOL;
                exit(1);
            }
            $content = file_get_contents($configFile);
            $content = str_replace('{' . $stream . '}', $port['no'], $content);
            file_put_contents($targetPath . 'config/autoload/server.php', $content);
            echo "创建项目:" . $targetPath . PHP_EOL;
        }
    }
};

/**
 * 创建nginx配置文件
 * @param $streamConfig
 * @param $nginxConfig
 */
$createNginx = function ($streamConfig, $nginxConfig) use ($nginxPath, $target, $appName) {

    $content = file_get_contents(__DIR__ . '/inittpl/up_nginx.conf');
    $tmpFile = [];
    foreach ($nginxConfig as $k => $v) {
        $tmpCon = $content;
        $listen = explode(',', $v['listen']);
        $tmpListen = '';
        foreach ($listen as $listenPort) {
            $tmpListen .= 'listen ' . $listenPort . ';' . PHP_EOL;
        }
        $tmpListen .= 'server_name ' . implode(' ', explode(',', $v['server_name'])) . ';' . PHP_EOL;
        $tmpCon = str_replace('{listen_server}', $tmpListen, $tmpCon);
        $stream = $v['upstream'];
        $root = $target . '/' . $stream . '_' . $streamConfig[$stream][0]['no'] . '/static';
        $tmpCon = str_replace('{root}', $root, $tmpCon);
        $tmpCon = str_replace('{upstream}', str_replace('conf_', '', $k), $tmpCon);
        if (!isset($streamConfig[$stream])) {
            echo '请填写已配置的upstream' . PHP_EOL;
            exit(1);
        }
        $tmpIps = '';
        foreach ($streamConfig[$stream] as $aPort) {
            $noUsePort = $aPort['no'];
            $tmpIps .= 'server 127.0.0.1:' . $noUsePort .';'. PHP_EOL;
        }
        $tmpCon = str_replace('{upips}', $tmpIps, $tmpCon);
        $tmpFile[] = $nginxPath . '/' . $appName . '_' . str_replace('conf_', '', $k) . '.conf';
        file_put_contents($nginxPath . '/' . $appName . '_' . str_replace('conf_', '', $k) . '.conf', $tmpCon);
        echo "创建nginx配置文件:" .$nginxPath . '/' . $appName . '_' . str_replace('conf_', '', $k) . '.conf' . PHP_EOL;
    }
    $nginxConfig = glob($nginxPath . '/' . $appName . '_*.conf');
    foreach ($nginxConfig as $v) {
        if (!in_array($v, $tmpFile)) {
            echo "删除nginx配置文件:" . $v .PHP_EOL;
            unlink($v);
        }
    }
};

/**
 * 创建supervisor配置文件
 * @param $streamConfig
 */
$createSupervisor = function ($streamConfig) use ($supervisorPath, $target, $appName, $php) {

    $content = file_get_contents(__DIR__ . '/inittpl/up_supervisor.conf');
    foreach ($streamConfig as $stream => $ports) {
        foreach ($ports as $port) {
            $targetPath = $target . '/' . $appName . '_' . $stream . '_' . $port['no'] . '/';
            $tmpPath = $supervisorPath . '/' . $appName . '_' . $stream . '_' . $port['no'] . '.conf';
            $tmp = $content;
            $tmp = str_replace('{name}', $appName . '_' . $stream . '_' . $port['no'], $tmp);
            $tmp = str_replace('{command}', $php . ' ' . $targetPath . 'bin/hyperf.php start', $tmp);
            file_put_contents($tmpPath, $tmp);
            echo "创建supervisor配置文件:" . $tmpPath . PHP_EOL;
        }
    }
};

/**
 * 删除supervisor配置文件
 * @param $streamConfig
 */
$delSuperVisor = function ($streamConfig) use ($supervisorPath, $target, $appName, $php) {

    $files = [];
    foreach ($streamConfig as $stream => $ports) {
        foreach ($ports as $port) {
            $files[] = $supervisorPath . '/' . $appName . '_' . $stream . '_' . $port['no'] . '.conf';
        }
    }
    $supervisorConfig = glob($supervisorPath . '/' . $appName . '_*.conf');
    foreach ($supervisorConfig as $v) {
        if (!in_array($v, $files)) {
            unlink($v);
            echo "删除supervisor配置文件:" . $v . PHP_EOL;
        }
    }
};

/**
 * 删除hyperf多余的项目文件
 * @param $streamConfig
 */
$delHyperf = function ($streamConfig) use ($target, $appName) {

    $tmpFiles = [];
    foreach ($streamConfig as $stream => $oneItem) {
        foreach ($oneItem as $port) {
            $tmpFiles[] = $target . '/' . $appName . '_' . $stream . '_' . $port['no'] . '';
        }
    }

    $files = glob($target . '/' . $appName . '_' . '*');
    foreach ($files as $v) {
        if (!in_array($v, $tmpFiles)) {
            shell_exec('rm -rf ' . $v);
            echo "删除hyperf配置文件:" . $v . PHP_EOL;
        }
    }
};

//创建nginx配置目录
$mkdirFuc($nginxPath);
//创建supervisor配置目录
$mkdirFuc($supervisorPath);
//解析配置
$streamConfig = [];
$nginxConfig = [];
foreach ($initConfig as $k => $v) {
    if (strpos($k, 'up_') !== false) {
        $streamConfig = array_merge($streamConfig, $portFunc($k, $v['port']));
    }
    if (strpos($k, 'conf_') !== false) {
        $nginxConfig[$k] = $v;
    }
}
//创建项目
$createHyperf($streamConfig);
//创建nginx配置文件
$createNginx($streamConfig, $nginxConfig);
//创建supervisor配置文件
$createSupervisor($streamConfig);
echo shell_exec($supervisorCtl . " update") . PHP_EOL;
echo "休眠10秒等到新的hyperf启动" . PHP_EOL;
sleep(10);
echo shell_exec($nginx . ' -s reload') . PHP_EOL;
echo "休眠10秒下线旧的hyperf项目" . PHP_EOL;
sleep(10);
$delSuperVisor($streamConfig);
$delHyperf($streamConfig);
echo shell_exec($supervisorCtl . " update") . PHP_EOL;
