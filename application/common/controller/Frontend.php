<?php

namespace app\common\controller;

use app\common\library\Auth;
use app\common\library\Btaction;
use think\Config;
use think\Controller;
use think\Hook;
use think\Lang;
use think\Loader;
use think\Validate;
use think\Debug;
use think\Cache;

/**
 * 前台控制器基类
 */
class Frontend extends Controller
{

    /**
     * 布局模板
     * @var string
     */
    protected $layout = '';

    /**
     * 无需登录的方法,同时也就不需要鉴权了
     * @var array
     */
    protected $noNeedLogin = [];

    /**
     * 无需鉴权的方法,但需要登录
     * @var array
     */
    protected $noNeedRight = [];

    /**
     * 权限Auth
     * @var Auth
     */
    protected $auth = null;

    public function _initialize()
    {
        //移除HTML标签
        $this->request->filter('trim,strip_tags,htmlspecialchars');
        $modulename = $this->request->module();
        $controllername = Loader::parseName($this->request->controller());
        $actionname = strtolower($this->request->action());

        // 如果有使用模板布局
        if ($this->layout) {
            $this->view->engine->layout('layout/' . $this->layout);
        }
        $this->auth = Auth::instance();

        // token
        $token = $this->request->server('HTTP_TOKEN', $this->request->request('token', \think\Cookie::get('token')));

        $path = str_replace('.', '/', $controllername) . '/' . $actionname;
        // 设置当前请求的URI
        $this->auth->setRequestUri($path);
        // 检测是否需要验证登录
        if (!$this->auth->match($this->noNeedLogin)) {
            //初始化
            $this->auth->init($token);
            //检测是否登录
            if (!$this->auth->isLogin()) {
                if ($this->request->isAjax()) {
                    $this->error(__('Please login first'), 'index/user/login');
                } else {
                    $this->redirect('index/user/login');
                }
            }
            // 判断是否需要验证权限
            if (!$this->auth->match($this->noNeedRight)) {
                // 判断控制器和方法判断是否有对应权限
                if (!$this->auth->check($path)) {
                    $this->error(__('You have no permission'));
                }
            }
        } else {
            // 如果有传递token才验证是否登录状态
            if ($token) {
                $this->auth->init($token);
            }
        }

        // 网站维护
        if (Config::get("site.status") == 1) {
            $this->request->isAjax() ? $this->error('网站维护中，请稍候再试') : sysmsg('网站维护中，请稍候再试');
        }

        $this->auth_check_local();

        // 已登录用户信息
        $this->view->assign('vhost', $this->auth->getUser());

        if ($this->auth->isLogin())
            // 用户组
            $this->view->assign('vhostGroup', $this->auth->getGroup());

        $this->view->assign('user', $this->auth->getUser());

        // 语言检测
        $lang = strip_tags($this->request->langset());

        $site = Config::get("site");

        $upload = \app\common\model\Config::upload();

        // 上传信息配置后
        Hook::listen("upload_config_init", $upload);
        // 软件配置
        $bty_config = Config::get('bty');
        unset($bty_config['AUTH_KEY']);

        // 配置信息
        $config = [
            'site'           => array_intersect_key($site, array_flip(['name', 'cdnurl', 'version', 'timezone', 'languages', 'iframe_cache', 'split_size'])),
            'upload'         => $upload,
            'modulename'     => $modulename,
            'controllername' => $controllername,
            'actionname'     => $actionname,
            'jsname'         => 'frontend/' . str_replace('.', '/', $controllername),
            'moduleurl'      => rtrim(url("/{$modulename}", '', false), '/'),
            'language'       => $lang,
            'bty'            => $bty_config,
        ];
        $config = array_merge($config, Config::get("view_replace_str"));

        Config::set('upload', array_merge(Config::get('upload'), $upload));

        // 配置信息后
        Hook::listen("config_init", $config);
        // 加载当前控制器语言包
        $this->loadlang($controllername);
        $this->assign('auth', $this->auth);
        $this->assign('site', $site);
        $this->assign('config', $config);
    }

    /**
     * 加载语言文件
     * @param string $name
     */
    protected function loadlang($name)
    {
        $name =  Loader::parseName($name);
        Lang::load(APP_PATH . $this->request->module() . '/lang/' . $this->request->langset() . '/' . str_replace('.', '/', $name) . '.php');
    }

    /**
     * 渲染配置信息
     * @param mixed $name  键名或数组
     * @param mixed $value 值
     */
    protected function assignconfig($name, $value = '')
    {
        $this->view->config = array_merge($this->view->config ? $this->view->config : [], is_array($name) ? $name : [$name => $value]);
    }

    /**
     * 刷新Token
     */
    protected function token()
    {
        $token = $this->request->param('__token__');

        //验证Token
        if (!Validate::make()->check(['__token__' => $token], ['__token__' => 'require|token'])) {
            $this->error(__('Token verification error'), '', ['__token__' => $this->request->token()]);
        }

        //刷新Token
        $this->request->token();
    }

    private static function getPublicKey()
    {
        return $public_key = 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCXySnlz6w8w0KOTz+XrzNd3+PmKJKAJRdKI4x5xNU8Q9EzWIYGyX2O1RK/FB1pwYjUVo8uNG6ghD48ZtRcumqPxU7uAHBlxq4S8zPPSGJ3NKgceRJEW/4oOFLw6jeJ1Pw3aHvg7hmxNxwgOLqlRzXDG8wBc7EqVTGa86qbfwZDEQIDAQAB';
    }

    // 远程授权验证
    private function auth_check($ip)
    {
        // 缓存器缓存远端获取的私钥
        $url = Config::get('bty.api_url') . '/auth_check.html';
        $data = [
            'obj' => Config::get('bty.APP_NAME'),
            'version' => Config::get('bty.version'),
            'domain' => $ip,
            'authCode' => Config::get('site.authCode'),
            'rsa' => 1,
        ];
        $json = \fast\Http::post($url, $data);
        return json_decode($json, 1);
    }

    // 授权验证方法
    protected function auth_check_local()
    {
        // 授权判断
        // TODO 新授权判断思路
        // 使用授权码获取远端私钥
        // 使用获取的远端私钥+本地公钥进行解密
        // 解密成功后获得当前授权域名
        // 如果当前域名全等于授权域名，则授权有效
        $bt = new Btaction();
        $ip = $bt->getIp();

        // 公钥
        $public_key = self::getPublicKey();

        $rsa = new \fast\Rsa($public_key);

        if (!Cache::get('auth_check')) {
            $json = $this->auth_check($ip);
            $curl = Cache::remember('auth_check', $json);
        } else {
            $curl = Cache::get('auth_check');
        }

        $is_ajax = $this->request->isAjax() ? 1 : 0;

        if ($curl && isset($curl['code']) && $curl['code'] == 1) {
            // 解密信息获取域名及有效期
            // 公钥解密
            $decode = $rsa->pubDecrypt($curl['encode']);
            if (!$decode) {
                return $is_ajax ? $this->error('授权信息错误') : sysmsg('授权信息错误');
            }
            $decode_arr = explode('|', $decode);
            // 检查授权域名是否为当前域名
            if ($decode_arr[0] != '9527' && $decode_arr[0] !== $ip) {
                return $is_ajax ? $this->error($ip . '授权信息不正确') : sysmsg($ip . '授权信息不正确');
            }
            // 检查授权是否过期
            if ($decode_arr[1] != 0 && time() > $decode_arr[1]) {
                return $is_ajax ? $this->error($ip . '授权已过期') : sysmsg($ip . '授权已过期');
            }
        } elseif (isset($curl['msg'])) {
            return $is_ajax ? $this->error($ip . $curl['msg']) : sysmsg($ip . $curl['msg']);
        } else {
            return $is_ajax ? $this->error($ip . '授权检查失败') : sysmsg($ip . '授权检查失败');
        }
    }
}