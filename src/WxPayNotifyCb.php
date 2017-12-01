<?php
/**
 * Created by PhpStorm.
 * User: lusc
 * Date: 2017/12/1
 * Time: 14:58
 */

namespace weapp;

include_once "3rd/WxpayAPI_php_v3/lib/WxPay.Api.php";

/**
 * weapp
 * User: lushuncheng<admin@lushuncheng.com>
 * Date: 2017/11/30
 * Time: 18:17
 * @link https://github.com/lscgzwd
 * @copyright Copyright (c) 2017 Lu Shun Cheng (https://github.com/lscgzwd)
 * @licence http://www.apache.org/licenses/LICENSE-2.0
 * @author Lu Shun Cheng (lscgzwd@gmail.com)
 * 微信公众号、小程序授权服务SDK
 * 微信支付相关
 */
class WxPayNotifyCb extends \WxPayNotify
{
    public $cbFunc;

    public function __construct($cbFunc)
    {
        $this->cbFunc = $cbFunc;
    }

    public function NotifyProcess($data, &$msg)
    {
        return call_user_func($this->cbFunc, $data, $msg);
    }
}