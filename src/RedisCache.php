<?php
/**
 * weapp
 * User: lushuncheng<admin@lushuncheng.com>
 * Date: 2017/11/30
 * Time: 18:17
 * @link https://github.com/lscgzwd
 * @copyright Copyright (c) 2017 Lu Shun Cheng (https://github.com/lscgzwd)
 * @licence http://www.apache.org/licenses/LICENSE-2.0
 * @author Lu Shun Cheng (lscgzwd@gmail.com)
 * Redis缓存实现
 */
namespace weapp;


class RedisCache extends BaseCache
{
    protected $redis;
    public function __construct($config)
    {
        $this->redis = new \Redis();
        if (isset($config['unixSock'])) {
            $this->redis->connect($config['unixSock']);
        } else {
            $this->redis->connect($config['host'], $config['port'] ?? 6379);
        }
        if ($config['password']) {
            $this->redis->auth($config['password']);
        }
        if (isset($config['prefix'])) {
            $this->redis->setOption(\Redis::OPT_PREFIX, $config['prefix']);
        }
        if (isset($config['serializer'])) {
            $this->redis->setOption(\Redis::OPT_SERIALIZER, $config['serializer']);
        }
    }
    public function removeCache($cacheName)
    {
        return $this->redis->del($cacheName);
    }
    public function getCache($cacheName)
    {
        return $this->getCache($cacheName);
    }

    /**
     * 设置缓存
     * @param $cacheName
     * @param $cacheValue
     * @param int $expireIn 过期时间，单位秒
     * @return bool
     */
    public function setCache($cacheName, $cacheValue, $expireIn)
    {
        if ($expireIn && $expireIn != -1) {
            return $this->redis->setex($cacheName, $expireIn, $cacheValue);
        } else {
            return $this->redis->set($cacheName, $cacheValue);
        }
    }
}