<?php

namespace Imi;

use Composer\Autoload\ClassLoader;
use Imi\Bean\Annotation;
use Imi\Bean\Annotation\AnnotationManager;
use Imi\Bean\Container;
use Imi\Bean\ReflectionContainer;
use Imi\Cache\CacheManager;
use Imi\Config\Dotenv\Dotenv;
use Imi\Core\App\Contract\IApp;
use Imi\Event\Event;
use Imi\Main\Helper;
use Imi\Main\Helper as MainHelper;
use Imi\Pool\PoolConfig;
use Imi\Pool\PoolManager;
use Imi\Util\AtomicManager;
use Imi\Util\Composer;
use Imi\Util\Coroutine;
use Imi\Util\CoroutineChannelManager;
use Imi\Util\Imi;
use Imi\Util\Text;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

final class App
{
    /**
     * 应用命名空间.
     *
     * @var string
     */
    private static string $namespace;

    /**
     * 容器.
     *
     * @var \Imi\Bean\Container
     */
    private static Container $container;

    /**
     * 框架是否已初始化.
     *
     * @var bool
     */
    private static bool $isInited = false;

    /**
     * 当前是否为调试模式.
     *
     * @var bool
     */
    private static bool $isDebug = false;

    /**
     * Composer ClassLoader.
     *
     * @var \Composer\Autoload\ClassLoader
     */
    private static ?ClassLoader $loader = null;

    /**
     * 运行时数据.
     *
     * @var RuntimeInfo
     */
    private static ?RuntimeInfo $runtimeInfo = null;

    /**
     * 是否协程服务器模式.
     *
     * @var bool
     */
    private static bool $isCoServer = false;

    /**
     * 上下文集合.
     *
     * @var array
     */
    private static array $context = [];

    /**
     * 只读上下文键名列表.
     *
     * @var string[]
     */
    private static array $contextReadonly = [];

    /**
     * imi 版本号.
     *
     * @var string
     */
    private static ?string $imiVersion = null;

    /**
     * App 实例对象
     *
     * @var \Imi\Core\App\Contract\IApp
     */
    private static IApp $app;

    private function __construct()
    {
    }

    /**
     * 框架服务运行入口.
     *
     * @param string $namespace 应用命名空间
     * @param string $app
     *
     * @return void
     */
    public static function run(string $namespace, string $app): void
    {
        self::$app = new $app($namespace);
        self::initFramework($namespace);
        self::$app->run();
    }

    /**
     * 框架初始化.
     *
     * @param string $namespace
     *
     * @return void
     */
    public static function initFramework(string $namespace)
    {
        static::$namespace = $namespace;
        $isServerStart = ('server/start' === ($_SERVER['argv'][1] ?? null));
        AnnotationManager::init();
        static::$runtimeInfo = new RuntimeInfo();
        static::$container = new Container();
        // .env
        $dotenv = new Dotenv(Imi::getNamespacePaths(static::$namespace));
        $dotenv->init();
        // 初始化Main类
        static::initMains();
        // 运行时目录写权限检测
        if (!is_writable($runtimePath = Imi::getRuntimePath()))
        {
            (new ConsoleOutput())->writeln('<error>Runtime path</error> <comment>' . $runtimePath . '</comment> <error>is not writable</error>');
            exit;
        }
        $input = new ArgvInput();
        // 框架运行时缓存支持
        if ($isServerStart)
        {
            $result = false;
        }
        elseif ($file = $input->getParameterOption('--imi-runtime'))
        {
            // 尝试加载指定 runtime
            $result = self::loadRuntimeInfo($file);
        }
        else
        {
            // 尝试加载默认 runtime
            $result = self::loadRuntimeInfo(Imi::getRuntimePath('imi-runtime.cache'));
        }
        if (!$result)
        {
            // 不使用缓存时去扫描
            Annotation::getInstance()->init([
                MainHelper::getMain('Imi', 'Imi'),
            ]);
            if ($isServerStart)
            {
                Imi::buildRuntime(Imi::getRuntimePath('imi-runtime-bak.cache'));
            }
        }
        static::$isInited = true;
        Event::trigger('IMI.INITED');
    }

    /**
     * 初始化应用.
     *
     * @param bool $noAppCache
     *
     * @return void
     */
    public static function initApp(bool $noAppCache): void
    {
        if ($noAppCache)
        {
            // 仅初始化项目及组件
            $initMains = [Helper::getMain(self::getNamespace())];
            foreach (Helper::getAppMains() as $main)
            {
                foreach ($main->getConfig()['components'] ?? [] as $namespace)
                {
                    $componentMain = Helper::getMain($namespace);
                    if (null !== $componentMain)
                    {
                        $initMains[] = $componentMain;
                    }
                }
            }
            Annotation::getInstance()->init($initMains);

            // 获取配置
            $pools = $caches = [];
            foreach (Helper::getMains() as $main)
            {
                $pools = array_merge($pools, $main->getConfig()['pools'] ?? []);
                $caches = array_merge($caches, $main->getConfig()['caches'] ?? []);
            }
            // 同步池子初始化
            foreach ($pools as $name => $pool)
            {
                if (isset($pool['sync']))
                {
                    $pool = $pool['sync'];
                    $poolPool = $pool['pool'];
                    PoolManager::addName($name, $poolPool['class'], new PoolConfig($poolPool['config']), $pool['resource']);
                }
                elseif (isset($pool['pool']['syncClass']))
                {
                    $poolPool = $pool['pool'];
                    PoolManager::addName($name, $poolPool['syncClass'], new PoolConfig($poolPool['config']), $pool['resource']);
                }
            }
            // 缓存初始化
            foreach ($caches as $name => $cache)
            {
                CacheManager::addName($name, $cache['handlerClass'], $cache['option'] ?? []);
            }
        }
        else
        {
            while (true)
            {
                $result = exec(Imi::getImiCmd('imi/buildRuntime', [], [
                    'format'        => 'json',
                    'imi-runtime'   => Imi::getRuntimePath('imi-runtime-bak.cache'),
                    'no-app-cache'  => true,
                ]), $output);
                $result = json_decode($result);
                if ('Build app runtime complete' === trim($result))
                {
                    break;
                }
                else
                {
                    if (null === $result)
                    {
                        echo implode(\PHP_EOL, $output), \PHP_EOL;
                    }
                    else
                    {
                        echo $result, \PHP_EOL;
                    }
                    sleep(1);
                }
            }
            self::loadRuntimeInfo(Imi::getRuntimePath('runtime.cache'));

            self::getBean('ErrorLog')->register();
            foreach (Helper::getMains() as $main)
            {
                $config = $main->getConfig();
                // 原子计数初始化
                AtomicManager::setNames($config['atomics'] ?? []);
            }
            AtomicManager::init();
        }
    }

    /**
     * 初始化Main类.
     *
     * @return void
     */
    private static function initMains()
    {
        // 框架
        if (!MainHelper::getMain('Imi', 'Imi'))
        {
            throw new \RuntimeException('Framework imi must have the class Imi\\Main');
        }
        // 项目
        if (!MainHelper::getMain(static::$namespace, 'app'))
        {
            throw new \RuntimeException(sprintf('Your app must have the class %s\\Main', static::$namespace));
        }
        // 服务器们
        $servers = array_merge(['main' => Config::get('@app.mainServer')], Config::get('@app.subServers', []));
        foreach ($servers as $serverName => $item)
        {
            if ($item && !MainHelper::getMain($item['namespace'], 'server.' . $serverName))
            {
                throw new \RuntimeException(sprintf('Server [%s] must have the class %s\\Main', $serverName, $item['namespace']));
            }
        }
    }

    /**
     * 创建服务器对象们.
     *
     * @return void
     */
    public static function createServers()
    {
        // 创建服务器对象们前置操作
        Event::trigger('IMI.SERVERS.CREATE.BEFORE');
        $mainServer = Config::get('@app.mainServer');
        if (null === $mainServer)
        {
            throw new \RuntimeException('config.mainServer not found');
        }
        // 主服务器
        ServerManage::createServer('main', $mainServer);
        // 创建监听子服务器端口
        $subServers = Config::get('@app.subServers', []);
        foreach ($subServers as $name => $config)
        {
            ServerManage::createServer($name, $config, true);
        }
        // 创建服务器对象们后置操作
        Event::trigger('IMI.SERVERS.CREATE.AFTER');
    }

    /**
     * 创建协程服务器.
     *
     * @param string $name
     * @param int    $workerNum
     *
     * @return \Imi\Server\CoServer
     */
    public static function createCoServer($name, $workerNum)
    {
        static::$isCoServer = true;
        $server = ServerManage::createCoServer($name, $workerNum);

        return $server;
    }

    /**
     * 是否协程服务器模式.
     *
     * @return bool
     */
    public static function isCoServer()
    {
        return static::$isCoServer;
    }

    /**
     * 获取应用命名空间.
     *
     * @return string
     */
    public static function getNamespace()
    {
        return static::$namespace;
    }

    /**
     * 获取容器对象
     *
     * @return \Imi\Bean\Container
     */
    public static function getContainer()
    {
        return static::$container;
    }

    /**
     * 获取Bean对象
     *
     * @param string $name
     *
     * @return mixed
     */
    public static function getBean($name, ...$params)
    {
        return static::$container->get($name, ...$params);
    }

    /**
     * 当前是否为调试模式.
     *
     * @return bool
     */
    public static function isDebug()
    {
        return static::$isDebug;
    }

    /**
     * 开关调试模式.
     *
     * @param bool $isDebug
     *
     * @return void
     */
    public static function setDebug($isDebug)
    {
        static::$isDebug = $isDebug;
    }

    /**
     * 框架是否已初始化.
     *
     * @return bool
     */
    public static function isInited()
    {
        return static::$isInited;
    }

    /**
     * 初始化 Worker，但不一定是 Worker 进程.
     *
     * @return void
     */
    public static function initWorker()
    {
        self::loadRuntimeInfo(Imi::getRuntimePath('runtime.cache'), true);

        // Worker 进程初始化前置
        Event::trigger('IMI.INIT.WORKER.BEFORE');

        $appMains = MainHelper::getAppMains();

        // 日志初始化
        if (static::$container->has('Logger'))
        {
            $logger = static::getBean('Logger');
            foreach ($appMains as $main)
            {
                foreach ($main->getConfig()['beans']['Logger']['exHandlers'] ?? [] as $exHandler)
                {
                    $logger->addExHandler($exHandler);
                }
            }
        }

        // 初始化
        PoolManager::clearPools();
        if (Coroutine::isIn())
        {
            $pools = Config::get('@app.pools', []);
            foreach ($appMains as $main)
            {
                // 协程通道队列初始化
                CoroutineChannelManager::setNames($main->getConfig()['coroutineChannels'] ?? []);

                // 异步池子初始化
                $pools = array_merge($pools, $main->getConfig()['pools'] ?? []);
            }
            foreach ($pools as $name => $pool)
            {
                if (isset($pool['async']))
                {
                    $pool = $pool['async'];
                    $poolPool = $pool['pool'];
                    PoolManager::addName($name, $poolPool['class'], new PoolConfig($poolPool['config']), $pool['resource']);
                }
                elseif (isset($pool['pool']['asyncClass']))
                {
                    $poolPool = $pool['pool'];
                    PoolManager::addName($name, $poolPool['asyncClass'], new PoolConfig($poolPool['config']), $pool['resource']);
                }
            }
        }
        else
        {
            $pools = Config::get('@app.pools', []);
            foreach ($appMains as $main)
            {
                // 同步池子初始化
                $pools = array_merge($pools, $main->getConfig()['pools'] ?? []);
            }
            foreach ($pools as $name => $pool)
            {
                if (isset($pool['sync']))
                {
                    $pool = $pool['sync'];
                    $poolPool = $pool['pool'];
                    PoolManager::addName($name, $poolPool['class'], new PoolConfig($poolPool['config']), $pool['resource']);
                }
                elseif (isset($pool['pool']['syncClass']))
                {
                    $poolPool = $pool['pool'];
                    PoolManager::addName($name, $poolPool['syncClass'], new PoolConfig($poolPool['config']), $pool['resource']);
                }
            }
        }

        // 缓存初始化
        CacheManager::clearPools();
        $caches = Config::get('@app.caches', []);
        foreach ($appMains as $main)
        {
            $caches = array_merge($caches, $main->getConfig()['caches'] ?? []);
        }
        foreach ($caches as $name => $cache)
        {
            CacheManager::addName($name, $cache['handlerClass'], $cache['option']);
        }

        // Worker 进程初始化后置
        Event::trigger('IMI.INIT.WORKER.AFTER');
    }

    /**
     * 设置 Composer ClassLoader.
     *
     * @param \Composer\Autoload\ClassLoader $loader
     *
     * @return void
     */
    public static function setLoader(\Composer\Autoload\ClassLoader $loader)
    {
        static::$loader = $loader;
    }

    /**
     * 获取 Composer ClassLoader.
     *
     * @return \Composer\Autoload\ClassLoader|null
     */
    public static function getLoader()
    {
        if (null == static::$loader)
        {
            static::$loader = Composer::getClassLoader();
        }

        return static::$loader;
    }

    /**
     * 获取运行时数据.
     *
     * @return RuntimeInfo
     */
    public static function getRuntimeInfo()
    {
        return static::$runtimeInfo;
    }

    /**
     * 从文件加载运行时数据
     * $minimumAvailable 设为 true，则 getRuntimeInfo() 无法获取到数据.
     *
     * @param string $fileName
     * @param bool   $minimumAvailable
     *
     * @return bool
     */
    public static function loadRuntimeInfo($fileName, $minimumAvailable = false)
    {
        if (!is_file($fileName))
        {
            return false;
        }
        $content = file_get_contents($fileName);
        static::$runtimeInfo = unserialize($content);
        if (!$minimumAvailable)
        {
            Annotation::getInstance()->getParser()->loadStoreData(static::$runtimeInfo->annotationParserData);
            Annotation::getInstance()->getParser()->setParsers(static::$runtimeInfo->annotationParserParsers);
        }
        AnnotationManager::setAnnotations(static::$runtimeInfo->annotationManagerAnnotations);
        AnnotationManager::setAnnotationRelation(static::$runtimeInfo->annotationManagerAnnotationRelation);
        foreach (static::$runtimeInfo->parsersData as $parserClass => $data)
        {
            $parser = $parserClass::getInstance();
            $parser->setData($data);
        }
        Event::trigger('IMI.LOAD_RUNTIME_INFO');
        if ($minimumAvailable)
        {
            static::$runtimeInfo = null;
        }

        return true;
    }

    /**
     * 获取应用上下文数据.
     *
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed
     */
    public static function get($name, $default = null)
    {
        return static::$context[$name] ?? $default;
    }

    /**
     * 设置应用上下文数据.
     *
     * @param string $name
     * @param mixed  $value
     * @param bool   $readonly
     *
     * @return void
     */
    public static function set($name, $value, $readonly = false)
    {
        if (isset(static::$contextReadonly[$name]))
        {
            $backtrace = debug_backtrace(\DEBUG_BACKTRACE_PROVIDE_OBJECT | \DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $backtrace = $backtrace[1] ?? null;
            if (!(
                (isset($backtrace['object']) && $backtrace['object'] instanceof \Imi\Bean\IBean)
                || (isset($backtrace['class']) && Text::startwith($backtrace['class'], 'Imi\\'))
            ))
            {
                throw new \RuntimeException('Cannot write to read-only application context');
            }
        }
        elseif ($readonly)
        {
            static::$contextReadonly[$name] = true;
        }
        static::$context[$name] = $value;
    }

    /**
     * 获取 imi 版本.
     *
     * @return string
     */
    public static function getImiVersion(): string
    {
        if (null !== static::$imiVersion)
        {
            return static::$imiVersion;
        }
        // composer
        $loader = static::getLoader();
        if ($loader)
        {
            $ref = ReflectionContainer::getClassReflection(\get_class($loader));
            $fileName = \dirname($ref->getFileName(), 3) . '/composer.lock';
            if (is_file($fileName))
            {
                $data = json_decode(file_get_contents($fileName), true);
                foreach ($data['packages'] ?? [] as $item)
                {
                    if ('yurunsoft/imi' === $item['name'])
                    {
                        return static::$imiVersion = $item['version'];
                    }
                }
            }
        }
        // git
        if (false !== strpos(`git --version`, 'git version') && preg_match('/\*([^\r\n]+)/', `git branch`, $matches) > 0)
        {
            return static::$imiVersion = trim($matches[1]);
        }

        return static::$imiVersion = 'Unknown';
    }

    /**
     * Get app 实例对象
     *
     * @return \Imi\Core\Contract\IApp
     */
    public static function getApp(): IApp
    {
        return static::$app;
    }
}
