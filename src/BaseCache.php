<?php
namespace weapp;
/**
 * weapp
 * User: lushuncheng<admin@lushuncheng.com>
 * Date: 2017/11/30
 * Time: 18:17
 * @link https://github.com/lscgzwd
 * @copyright Copyright (c) 2017 Lu Shun Cheng (https://github.com/lscgzwd)
 * @licence http://www.apache.org/licenses/LICENSE-2.0
 * @author Lu Shun Cheng (lscgzwd@gmail.com)
 * 缓存抽象类
 */
abstract class BaseCache
{
    abstract public function setCache($cacheName,$cacheValue,$expireIn);
    abstract public function getCache($cacheName);
    abstract public function removeCache($cacheName);
}