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
 * 文件缓存实现
 */
class FileCache extends BaseCache
{
    protected $cacheDir = "";

    public function __construct($cacheDir)
    {
        $this->cacheDir = rtrim($cacheDir, '/ \\') . DIRECTORY_SEPARATOR;
        if (!file_exists($this->cacheDir)) @mkdir($this->cacheDir, 0777, true);
    }


    /**
     * 缓存数据
     * @param string $cacheName  缓存名称
     * @param string $cacheValue 缓存值
     * @param int $expireIn 缓存时间，单位秒 如果为-1则为永不过期
     */
    public function setCache($cacheName, $cacheValue, $expireIn)
    {
        $filePath = $this->cacheDir . $cacheName;
        $arr = ['v' => $cacheValue, 'et' => time() + $expireIn - 60];
        if ($expireIn == -1) $arr['et'] = -1;
        file_put_contents($filePath, json_encode($arr));
    }

    /**
     * 获取缓存
     * @param string $cacheName
     * @return bool|string
     */
    public function getCache($cacheName)
    {
        $filePath = $this->cacheDir . $cacheName;
        if (!file_exists($filePath)) return false;
        $arr = json_decode(file_get_contents($filePath), true);
        if ($arr['et'] == -1 || $arr['et'] > time()) return $arr['v'];
        return false;
    }

    /**
     * 删除缓存
     * @param string $cacheName
     */
    public function removeCache($cacheName)
    {
        $filePath = $this->cacheDir . $cacheName;
        if (file_exists($filePath)) @unlink($filePath);
    }
}