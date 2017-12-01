<?php
namespace weapp;

if (!class_exists("Wechat2")) {
    include_once __DIR__ . "/3rd/wechat/Wechat2.class.php";
}

/**
 * weapp
 * User: lushuncheng<admin@lushuncheng.com>
 * Date: 2017/11/30
 * Time: 18:17
 * @link https://github.com/lscgzwd
 * @copyright Copyright (c) 2017 Lu Shun Cheng (https://github.com/lscgzwd)
 * @licence http://www.apache.org/licenses/LICENSE-2.0
 * @author Lu Shun Cheng (lscgzwd@gmail.com)
 * 微信、小程序第三方平台SDK
 */
class WxComponentService
{

    /**
     * 第三方平台对象
     * @var WxComponent
     */
    protected $wxComponent;

    /**
     * 第三方平台AppId
     * @var string
     */
    protected $wxComponentAppId;

    /**
     * 平台配置
     * @var array 例:array('component_appid'=>,'component_appsecret'=>,'encodingAesKey'=>,'token')
     */
    protected $wxComponentConfig;

    /**
     * 缓存类
     * @var BaseCache
     */
    protected $cache;
    /**
     * @var callable $logCallback
     */
    public $logCallback = null;

    /**
     * @param BaseCache $cache
     */
    public function setCache(BaseCache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @param callable $logCallback
     */
    public function setLogCallback(callable $logCallback)
    {
        $this->logCallback = $logCallback;
    }

    /**
     * @param bool $debug
     */
    public function setDebug(bool $debug)
    {
        $this->debug = $debug;
    }
    /**
     * @var bool
     */
    public $debug = false;

    public function __construct($wxComponentConfig, BaseCache $cache)
    {
        $this->cache             = $cache;
        $this->wxComponentAppId  = $wxComponentConfig['component_appid'];
        $this->wxComponentConfig = $wxComponentConfig;
    }

    /**
     * 得到第三方对象，配置采用全局配置
     * @return WxComponent
     */
    public function getWxComponent()
    {
        $cfg                            = $this->wxComponentConfig;
        $cfg['component_verify_ticket'] = $this->getComponentVerifyTicket();
        if (!$this->wxComponent) {
            $this->wxComponent = new WxComponent($cfg['component_appid'],
                $cfg['component_appsecret'], $cfg['component_verify_ticket'],
                $cfg['encodingAesKey'], $cfg['token']);
        }

        return $this->wxComponent;
    }
    protected function log($log)
    {
        if ($this->debug && is_callable($this->logCallback)) {
            if (is_array($log)) {
                $log = print_r($log, true);
            }

            return call_user_func($this->logCallback, $log);
        }
        return true;
    }

    /**
     * 得到跳转授权公众号的URL，回调返回时，会有component_appid参数
     * @param string $redirectUrl 跳转回来的URL，可以含有参数用于回调识别
     * @return string
     */
    public function getAuthorizeUrl($redirectUrl)
    {
        $linkSymbo    = strpos($redirectUrl, "?") ? "&" : "?";
        $redirect_uri = $redirectUrl . $linkSymbo . "component_appid=" . urlencode($this->wxComponentAppId);
        $preAuthCode  = $this->getPreAuthCode();
        return $this->getWxComponent()->getAuthCbUrl($preAuthCode, $redirect_uri);
    }

    /**
     * 公众号授权回调处理过程
     * @param $authCode
     * @param $expireIn
     * @return array
     *  错误返回：array('code'=>(!=0),'msg'=>)
     *  成功返回：array('code'=>0,'appAccountInfo'=>$appAccountInfo)
     *  $appAccountInfo 授权的公众号信息，格式如下：{"authorizer_info": {
     *    "nick_name": "微信SDK Demo Special",
     *    "head_img": "http://wx.qlogo.cn/mmopen/GPyw0pGicibl5Eda4GmSSbTguhjg9LZjumHmVjybjiaQXnE9XrXEts6ny9Uv4Fk6hOScWRDibq1fI0WOkSaAjaecNTict3n6EjJaC/0",
     *    "service_type_info": { "id": 2 },
     *    "verify_type_info": { "id": 0 },
     *    "user_name":"gh_eb5e3a772040",
     *    "alias":"paytest01"
     *    },
     *    "authorization_info": {
     *    "appid": "wxf8b4f85f3a794e77",
     *    "func_info": [    { "funcscope_category": { "id": 1 } },    { "funcscope_category": { "id": 2 } },    { "funcscope_category": { "id": 3 } }]
     *    }}
     */
    public function authorizeCallbackProcess($authCode, $expireIn)
    {
        $authName = "wxAppAuthCode" . $this->wxComponentAppId; // 通过authcode换取公众号的接口调用凭据
        try {
            $this->cache->setCache($authName, $authCode, $expireIn);
            $componentAccessToken = $this->getComponentAccessTocken();
            $authInfo             = $this->getWxComponent()->getWxAuthInfo($componentAccessToken, $authCode);
            if (!$authInfo) {
                return array('code' => $this->getWxComponent()->errCode, 'msg' => $this->getWxComponent()->errMsg);
            }

            $authName = "wxAppAccessToken" . $this->wxComponentAppId . "_" . $authInfo['authorization_info']['authorizer_appid'];
            $this->cache->setCache($authName, $authInfo['authorization_info']['authorizer_access_token'], $authInfo['authorization_info']['expires_in']);

            $authName = "wxAppRefreshToken" . $this->wxComponentAppId . "_" . $authInfo['authorization_info']['authorizer_appid'];
            $this->cache->setCache($authName, $authInfo['authorization_info']['authorizer_refresh_token'], -1);

            $appAccountInfo = $this->getWxComponent()->getWxAccountInfo($componentAccessToken, $authInfo['authorization_info']['authorizer_appid']);
            if (!$appAccountInfo) {
                return array('code' => $this->getWxComponent()->errCode, 'msg' => $this->getWxComponent()->errMsg);
            }
            return array('code' => 0, 'appAccountInfo' => $appAccountInfo, 'authInfo' => $authInfo);
        } catch (\Throwable $e) {
            $this->log($e->__toString());
            return [
                'code' => $e->getCode(),
                'msg'  => $e->getMessage(),
            ];
        }
    }

    /**
     * 第三方平台事件接收处理:
     *      1、微信服务器每隔10分钟会向第三方的消息接收地址推送一次component_verify_ticket，用于获取第三方平台接口调用凭据
     *      2、取消授权事件的处理
     * @param string $raw
     * @return array
     * AppId 授权公众号
     * InfoType component_verify_ticket(ticket通知) unauthorized(取消授权通知) authorized(授权成功通知) updateauthorized(授权更新通知)
     * POST数据示例（component_verify_ticket通知）
     * <xml>
     * <AppId> </AppId>
     * <CreateTime>1413192605 </CreateTime>
     * <InfoType>component_verify_ticket</InfoType>
     * <ComponentVerifyTicket> </ComponentVerifyTicket>
     * </xml>
     * POST数据示例（取消授权通知）
     * <xml>
     * <AppId>第三方平台appid</AppId>
     * <CreateTime>1413192760</CreateTime>
     * <InfoType>unauthorized</InfoType>
     * <AuthorizerAppid>公众号appid</AuthorizerAppid>
     * </xml>
     * POST数据示例（授权成功通知）
     * <xml>
     * <AppId>第三方平台appid</AppId>
     * <CreateTime>1413192760</CreateTime>
     * <InfoType>authorized</InfoType>
     * <AuthorizerAppid>公众号appid</AuthorizerAppid>
     * <AuthorizationCode>授权码（code）</AuthorizationCode>
     * <AuthorizationCodeExpiredTime>过期时间</AuthorizationCodeExpiredTime>
     * </xml>
     * POST数据示例（授权更新通知）
     * <xml>
     * <AppId>第三方平台appid</AppId>
     * <CreateTime>1413192760</CreateTime>
     * <InfoType>updateauthorized</InfoType>
     * <AuthorizerAppid>公众号appid</AuthorizerAppid>
     * <AuthorizationCode>授权码（code）</AuthorizationCode>
     * <AuthorizationCodeExpiredTime>过期时间</AuthorizationCodeExpiredTime>
     * </xml>
     */
    public function onComponentEventNotify($raw)
    {
        $ret = $this->getWxComponent()->processEventNotify($raw);
        if (is_array($ret)) {
            switch ($ret['InfoType']) {
                case "component_verify_ticket":
                    $authName = "wxComponentVerifyTicket" . $this->wxComponentAppId;
                    $this->cache->setCache($authName, $ret['ComponentVerifyTicket'], -1);
                    break;
                case "unauthorized":
                    // 移除授权缓存
                    $authName = "wxAppAccessToken" . $this->wxComponentAppId . "_" . $ret['AuthorizerAppid'];
                    $this->cache->removeCache($authName);

                    $authName = "wxAppRefreshToken" . $this->wxComponentAppId . "_" . $ret['AuthorizerAppid'];
                    $this->cache->removeCache($authName);
                    break;
                case "authorized":
                    $this->authorizeCallbackProcess($ret['AuthorizationCode'], $ret['AuthorizationCodeExpiredTime']);
                    break;
                case "updateauthorized":
                    $this->authorizeCallbackProcess($ret['AuthorizationCode'], $ret['AuthorizationCodeExpiredTime']);
                    break;
            }
        }
        return $ret;
    }

    /**
     * 到授权后的公众号对象，代理处理公众号实现业务操作
     * @param string $appId 公众号appId
     * @return \Wechat2|boolean
     */
    public function getWechat($appId)
    {
        try {
            $cfg            = $this->wxComponentConfig;
            $appAccessToken = $this->getAppAccessToken($appId);
            if (!$appAccessToken) {
                return false;
            }

            $Wechat2_options = array(
                'token'          => $cfg['token'],
                'encodingaeskey' => $cfg['encodingAesKey'],
                'appid'          => $cfg['component_appid'],
                'appsecret'      => $cfg['component_appsecret'],
                'access_token'   => $appAccessToken,
            );
            return new \Wechat2($Wechat2_options);
        } catch (\Throwable $e) {
            $this->log($e->__toString());
            return false;
        }
//        $_ins[$appId] = new Wechat2($Wechat2_options);
        //        return $_ins[$appId];
    }

    /**
     * 判断是否授权公众号是否有效，如果授权过期或者公众号取消授权，则返回false。
     * @param string $appId 授权的公众号
     * @return bool|string
     */
    public function isValidAuthorizedAppId($appId)
    {
        return $this->getAppAccessToken($appId);
    }

    /**
     * 得到授权公众号的接口调用凭据
     * @param  string $appId 授权公众号AppId
     * @return bool|string 接口调用凭据
     */
    public function getAppAccessToken($appId)
    {
        $authName       = "wxAppAccessToken" . $this->wxComponentAppId . "_" . $appId;
        $appAccessToken = $this->cache->getCache($authName);
        if ($appAccessToken) {
            return $appAccessToken;
        }

        $componentAccessToken = $this->getComponentAccessTocken();

        $authName        = "wxAppRefreshToken" . $this->wxComponentAppId . "_" . $appId;
        $appRefreshToken = $this->cache->getCache($authName);
        if (!$appRefreshToken) {
            return false;
        }

        $refreshTokenInfo = $this->getWxComponent()->getWxAccessToken($componentAccessToken, $appId, $appRefreshToken);
        if (!$refreshTokenInfo) {
            return false;
        }

        $authName = "wxAppAccessToken" . $this->wxComponentAppId . "_" . $appId;
        $this->cache->setCache($authName, $refreshTokenInfo['authorizer_access_token'], $refreshTokenInfo['expires_in']);

        $authName = "wxAppRefreshToken" . $this->wxComponentAppId . "_" . $appId;
        $this->cache->setCache($authName, $refreshTokenInfo['authorizer_refresh_token'], -1);
        return $refreshTokenInfo['authorizer_access_token'];
    }

    /**
     * 得到预授权码
     * @return string
     */
    protected function getPreAuthCode()
    {
        $authName    = "wxPreAuthCode" . $this->wxComponentAppId;
        $preAuthCode = $this->cache->getCache($authName);
        if ($preAuthCode) {
            return $preAuthCode;
        }

        $componentAccessToken = $this->getComponentAccessTocken();
        $preAuthCodeArr       = $this->getWxComponent()->getPreauthCode($componentAccessToken);
        $this->cache->setCache($authName, $preAuthCodeArr['pre_auth_code'], $preAuthCodeArr['expires_in'] - 10);
        return $preAuthCodeArr['pre_auth_code'];
    }

    /**
     * 得到接口调用凭据
     * @return bool|string
     */
    protected function getComponentAccessTocken()
    {
        $authName              = "wxComponentAccessTocken" . $this->wxComponentAppId;
        $componentAccessTocken = $this->cache->getCache($authName);
        if ($componentAccessTocken) {
            return $componentAccessTocken;
        }

        $accessArr = $this->getWxComponent()->getAccessToken();
        $this->cache->setCache($authName, $accessArr['component_access_token'], $accessArr['expires_in'] - 10);
        return $accessArr['component_access_token'];
    }

    /**
     * 得到微信服务器定时推过来的component_verify_ticket
     * @return bool|string
     * @throws \Exception
     */
    protected function getComponentVerifyTicket()
    {
        $authName              = "wxComponentVerifyTicket" . $this->wxComponentAppId;
        $ComponentVerifyTicket = $this->cache->getCache($authName);
        return $ComponentVerifyTicket;
    }

    /**
     * 代公众号发起网页授权 oauth 授权跳转接口
     * @param string $appId 公众号appId
     * @param string $callback 跳转URL
     * @param string $state 状态信息，最多128字节
     * @param string $scope 授权作用域 snsapi_base或者snsapi_userinfo 或者 snsapi_base,snsapi_userinfo
     * @return string
     */
    public function getOauthRedirect($appId, $callback, $state = '', $scope = 'snsapi_base')
    {
        return $this->getWxComponent()->getOauthRedirect($appId, $callback, $state, $scope);
    }

    /**
     * 代公众号发起网页授权 回调URL时，通过code获取Access Token
     * @param string $appId
     * @return array {access_token,expires_in,refresh_token,openid,scope}
     */
    public function getOauthAccessTokenForCode($appId)
    {
        $ret = $this->getWxComponent()->getOauthAccessToken($appId, $this->getComponentAccessTocken());
        if ($ret) {
            $authName = "wxComponentOauthToken" . $this->wxComponentAppId . "_" . $appId;
            $this->cache->setCache($authName, $ret['access_token'], $ret['expires_in']);
            $authName = "wxComponentOauthRefreshToken" . $this->wxComponentAppId . "_" . $appId;
            $this->cache->setCache($authName, $ret['refresh_token'], 30 * 24 * 2600); // refresh_token30天有效期
        }
        return $ret;
    }

    /**
     * 代公众号发起网页授权 获取缓存的accessToken，如果为缓存没有，则通过刷新token重新获取
     * @param $appId
     * @return bool|string
     */
    public function getOauthAccessToken($appId)
    {
        $authName    = "wxComponentOauthToken" . $this->wxComponentAppId . "_" . $appId;
        $accessToken = $this->cache->getCache($authName);
        if ($accessToken) {
            return $accessToken;
        }

        $authName     = "wxComponentOauthRefreshToken" . $this->wxComponentAppId . "_" . $appId;
        $refreshToken = $this->cache->getCache($authName);
        if (!$refreshToken) {
            return false;
        }

        $ret = $this->getWxComponent()->getOauthRefreshToken($appId, $refreshToken, $this->getComponentAccessTocken());
        if ($ret) {
            $authName = "wxComponentOauthToken" . $this->wxComponentAppId . "_" . $appId;
            $this->cache->setCache($authName, $ret['access_token'], $ret['expires_in']);
            $authName = "wxComponentOauthRefreshToken" . $this->wxComponentAppId . "_" . $appId;
            $this->cache->setCache($authName, $ret['refresh_token'], 30 * 24 * 2600); // refresh_token30天有效期
        }
        return $ret['access_token'];
    }

    /**
     * 代公众号发起网页授权，取得openid
     * @param string $appId
     * @param string $callbackUrl 网页回调URL
     * @return bool|string
     */
    public function getOauthOpenId($appId, $callbackUrl = null)
    {
        if (!isset($_GET['code'])) {
            $url = $this->getOauthRedirect($appId, $callbackUrl);
            header("Location: {$url}");
            exit;
        } else {
            $authInfo = $this->getOauthAccessTokenForCode($appId);
            return $authInfo['openid'];
        }
    }

    /**
     * 获取授权后的用户资料
     * @param string $accessToken
     * @param string $openid
     * @return array {openid,nickname,sex,province,city,country,headimgurl,privilege,[unionid]}
     * 注意：unionid字段 只有在用户将公众号绑定到微信开放平台账号后，才会出现。建议调用前用isset()检测一下
     */
    public function getOauthUserinfo($accessToken, $openid)
    {
        return $this->getWxComponent()->getOauthUserinfo($accessToken, $openid);
    }

    /**
     * 代公众号使用JS SDK时，JS SDK的配置信息
     * @param string $appId 公众号appId  必须经过授权过，并缓存了access_token
     * @param string $url 当前页面URL
     * @return array|bool
     */
    public function getJsSign($appId, $url)
    {
        $jsTicket = $this->getJsTicket($appId);
        if ($jsTicket) {
            $weObj                = $this->getWechat($appId);
            $weObj->jsapi_ticket  = $jsTicket;
            $signPackage          = $weObj->getJsSign($url);
            $signPackage['appId'] = $appId;
            return $signPackage;
        }
        return false;
    }

    /**
     * 代替公众号使用JS SDK时，获取jsapi_ticket
     * @param string $appId 公众号appId 必须经过授权过，并缓存了access_token
     * @return bool
     */
    public function getJsTicket($appId)
    {
        $authName = "wxComponentJsTicket" . $this->wxComponentAppId . "_" . $appId;
        $jsTicket = $this->cache->getCache($authName);
        if ($jsTicket) {
            return $jsTicket;
        }

        $weObj = $this->getWechat($appId);
        if (!$weObj) {
            return false;
        }

        $json = $weObj->getJsTicket2($appId);
        if ($json) {
            $this->cache->setCache($authName, $json['ticket'], $json['expires_in']);
            return $json['ticket'];
        }
        return false;
    }

    /**
     * 获取拉取适用卡券列表的签名包 用于js sdk 的 wx.chooseCard
     * @param string $appId 公众号appid
     * @param string $card_type 卡券的类型，不可为空，官方jssdk文档说这个值可空，但签名验证工具又必填这个值，官方文档到处是坑，
     *      GROUPON团购券  CASH代金券 DISCOUNT折扣券 GIFT 优惠券 GENERAL_COUPON  MEMBER_CARD
     * @param string $card_id 卡券的ID，可空
     * @param string $code 卡券自定义code
     * @param string $location_id 卡券的适用门店ID，可空
     * @return array|bool
     *
     * wx.chooseCard({
     * shopId: '', // 门店Id
     * cardType: '', // 卡券类型
     * cardId: '', // 卡券Id
     * timestamp: 0, // 卡券签名时间戳
     * nonceStr: '', // 卡券签名随机串
     * signType: '', // 签名方式，默认'SHA1'
     * cardSign: '', // 卡券签名
     * success: function (res) {
     * var cardList= res.cardList; // 用户选中的卡券列表信息
     * }
     * });
     */
    public function getChooseCardSign($appId, $card_type = '', $card_id = '', $code = '', $location_id = '')
    {
        $jsCardTicket = $this->getJsCardTicket($appId);
        if ($jsCardTicket) {
            $weObj             = $this->getWechat($appId);
            $weObj->api_ticket = $jsCardTicket;
            $signPackage       = $weObj->getCardSign($card_type, $card_id, $code, $location_id, $appId);
            return $signPackage;
        }
        return false;
    }

    /**
     * 获取添加卡券的签名信息 用于js sdk的wx.addCard
     * @param string $appId 公众号appid
     * @param string $card_id 卡券的ID，可空
     * @param string $code 卡券自定义code
     * @param string $openid 用户openid
     * @param string $balance 用户余额
     * @return array|bool
     *
     * wx.addCard({
     *    cardList: [{
     *    cardId: '',
     *    cardExt: ''
     *    }], // 需要添加的卡券列表
     *    success: function (res) {
     *    var cardList = res.cardList; // 添加的卡券列表信息
     *    }
     *    });
     */
    public function getAddCardExt($appId, $card_id = '', $code = '', $openid = '', $balance = '')
    {
        $jsCardTicket = $this->getJsCardTicket($appId);
        if ($jsCardTicket) {
            $weObj             = $this->getWechat($appId);
            $weObj->api_ticket = $jsCardTicket;
            $timestamp         = 0;
            $nonceStr          = '';
            $signPackage       = $weObj->getAddCardSign($card_id, $code, $timestamp, $nonceStr, $openid, $balance);
            $ext               = array(
                'code'      => $code,
                'openid'    => $openid,
                'timestamp' => strval($signPackage['timestamp']),
                'signature' => $signPackage['cardSign'],
                'nonce_str' => strval($signPackage['nonceStr']),
            );
            return $ext;
        }
        return false;
    }

    /**
     * 代替公众号使用卡券时，获取卡券ticket
     * @param $appId
     * @return bool
     */
    public function getJsCardTicket($appId)
    {
        $authName     = "wxComponentJsCardTicket" . $this->wxComponentAppId . "_" . $appId;
        $jsCardTicket = $this->cache->getCache($authName);
        if ($jsCardTicket) {
            return $jsCardTicket;
        }

        $weObj = $this->getWechat($appId);
        if (!$weObj) {
            return false;
        }
        $json = $weObj->getJsCardTicket($appId);
        if ($json) {
            $this->cache->setCache($authName, $json['ticket'], $json['expires_in']);
            return $json['ticket'];
        }
        return false;
    }

    /**
     * 代设置小程序域名
     * @param $appID
     * @param $params
     * @return bool
     */
    public function setMiniProgramDomain($appID, $params)
    {
        return $this->getWxComponent()->setMiniProgramDomain($appID, $params, $this->getAppAccessToken($appID));
    }

    /**
     * 给小程序上传代码
     * @param $appID
     * @param $params
     * @return bool
     */
    public function uploadTemplate($appID, $params)
    {
        return $this->getWxComponent()->uploadTemplate($params, $this->getAppAccessToken($appID));
    }

    /**
     * 获取草稿模板列表
     * @return bool|mixed
     */
    public function getDraftTemplateList()
    {
        return $this->getWxComponent()->getDraftTemplateList($this->getComponentAccessTocken());
    }

    /**
     * 获取上线模板列表
     * @return bool|mixed
     */
    public function getTemplateList()
    {
        return $this->getWxComponent()->getTemplateList($this->getComponentAccessTocken());
    }

    /**
     * 将草稿里面的模板上线
     * @param $draftID
     * @return bool
     */
    public function auditDraftTemplate($draftID)
    {
        return $this->getWxComponent()->auditDraftTemplate($this->getComponentAccessTocken(), $draftID);
    }

    /**
     * 删除模板库里面的模板
     * @param $templateID
     * @return bool
     */
    public function deleteTemplate($templateID)
    {
        return $this->getWxComponent()->deleteTemplate($this->getComponentAccessTocken(), $templateID);
    }

    /**
     * 获取体验二维码
     * @param $appid
     * @return bool|string
     */
    public function getQrCode($appid)
    {
        return $this->getWxComponent()->getQrCode($this->getAppAccessToken($appid));
    }

    /**
     * 获取小程序的分类配置
     * @param $appid
     * @return bool|mixed
     */
    public function getCategory($appid)
    {
        return $this->getWxComponent()->getCategory($this->getAppAccessToken($appid));
    }

    /**
     * 获取第三方提交的代码页面配置
     * @param $appid
     * @return bool|mixed
     */
    public function getPages($appid)
    {
        return $this->getWxComponent()->getPages($this->getAppAccessToken($appid));
    }

    /**
     * 提交代码审核
     * @param $appid
     * @param $params
     * @return bool|mixed
     */
    public function auditTemplate($appid, $params)
    {
        return $this->getWxComponent()->auditTemplate($params, $this->getAppAccessToken($appid));
    }

    /**
     * 绑定测试用户
     * @param $appid
     * @param $username
     * @return bool
     */
    public function bindTestUser($appid, $username)
    {
        return $this->getWxComponent()->bindTestUser(['wechatid' => $username], $this->getAppAccessToken($appid));
    }

    /**
     * 解绑定测试用户
     * @param $appid
     * @param $username
     * @return bool
     */
    public function unbindTestUser($appid, $username)
    {
        return $this->getWxComponent()->unbindTestUser(['wechatid' => $username], $this->getAppAccessToken($appid));
    }

    /**
     * 全网发布小程序代码
     * @param $appid
     * @return bool
     */
    public function publishTemplate($appid)
    {
        return $this->getWxComponent()->publishTemplate($this->getAppAccessToken($appid));
    }

    /**
     * 获取小程序序代码审核状态
     * @param $appid
     * @param $auditid
     * @return bool|mixed
     */
    public function auditStatus($appid, $auditid)
    {
        return $this->getWxComponent()->getAuditStatus($auditid, $this->getAppAccessToken($appid));
    }
}
