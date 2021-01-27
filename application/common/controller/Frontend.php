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

        if (!Config('site.debug')) {
            error_reporting(E_ALL ^ E_NOTICE);
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

        // 静态资源版本号
        $static_version = Config::get('app_debug')||Config::get('site.debug')?time():Config::get('bty.version');

        // 配置信息后
        Hook::listen("config_init", $config);
        // 加载当前控制器语言包
        $this->loadlang($controllername);
        $this->assign('auth', $this->auth);
        $this->assign('site', $site);
        $this->assign('config', $config);
        $this->assign('static_version',$static_version);
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
        $url = Config::get('bty.api_url') . '/bthost_auth_check.html';
        $data = [
            'obj' => Config::get('bty.APP_NAME'),
            'version' => Config::get('bty.version'),
            'domain' => $ip,
            'rsa' => 1,
        ];
        $json = \fast\Http::post($url, $data);
        return json_decode($json, 1);
    }

    // 授权验证方法
    protected function auth_check_local()
    {
        $is_ajax = $this->request->isAjax() ? 1 : 0;
        // 公钥
        $public_key = self::getPublicKey();

        $rsa = new \fast\Rsa($public_key);

        if (!Cache::get('auth_check_ip')) {
            $bt = new Btaction();
            $ipInfo = $bt->getIp();
            $ip = $ipInfo;
            if (!$ip) {
                $msg = __('The current server public network IP acquisition failed, please make sure that your panel has public network capability, and check whether the server communication and key are correct');
                return $is_ajax ? $this->error($msg) : sysmsg($msg);
            }
            // ip需要密文加密
            $ip_encode = encode($ipInfo, 'ZD4wNqBVN0Gn');
            Cache::remember('auth_check_ip', $ip_encode, 0);
        } else {
            $ip_encode = Cache::get('auth_check_ip');
            $ip = decode($ip_encode, 'ZD4wNqBVN0Gn');
            if (!$ip) {
                $msg = __('The current server public network IP acquisition failed, please make sure that your panel has public network capability, and check whether the server communication and key are correct') . '，' . __('Or try to delete the directory /runtime/cache and try again!');
                return $is_ajax ? $this->error($msg) : sysmsg($msg);
            }
        }

        // 离线授权
        if (Config::get('auth.code')) {
            $security_code = Config::get('auth.code');
        } else {
            // 线上授权
            $curl = $this->auth_check($ip);
            if ($curl && isset($curl['code']) && $curl['code'] == 1) {
                $security_code = $curl['encode'];
            } elseif (isset($curl['msg'])) {
                $msg = $ip . $curl['msg'];
                return $is_ajax ? $this->error($msg) : sysmsg($msg);
            } else {
                $msg = $ip . __('Authorization check failed');
                return $is_ajax ? $this->error($msg) : sysmsg($msg);
            }
        }

        if ($security_code) {
            // 解密信息获取域名及有效期
            // 公钥解密
            $decode = $rsa->pubDecrypt($security_code);
            if (!$decode) {
                return $is_ajax ? $this->error(__('security_code error')) : sysmsg(__('security_code error'));
            }
            $decode_arr = explode('|', $decode);

            list($domain, $auth_expiration_time) = $decode_arr;

            // 检查授权域名是否为当前域名
            if (
                $domain != '9527' && $domain !== $ip
            ) {
                return $is_ajax ? $this->error($ip . __('Authorization information error, please request authorization again or obtain authorization code')) : sysmsg($ip . __('Authorization information error, please request authorization again or obtain authorization code'));
            }

            // 检查授权是否过期
            if (
                $auth_expiration_time != 0 && time() > $auth_expiration_time
            ) {
                return $is_ajax ? $this->error($ip . __('Authorization expired')) : sysmsg($ip . __('Authorization expired'));
            }

            // 记录离线授权码
            if ($security_code && !Config::get('auth.code')) {
                $auth_file = APP_PATH . 'extra' . DS . 'auth.php';
                // 判断文件是否存在
                if (!file_exists($auth_file)) {
                    // 不存在则创建
                    $createfile = @fopen($auth_file, "a+");
                    $content = "<?php return ['code' => '',];";
                    @fputs($createfile, $content);
                    @fclose($createfile);
                    if (!$createfile) {
                        $msg = __('The current permissions are insufficient to write the file %s', $auth_file);
                        return $is_ajax ? $this->error($msg) : sysmsg($msg);
                    }
                }
                // 写入code
                $config = include $auth_file;
                $config['code'] = $security_code;
                file_put_contents($auth_file, '<?php' . "\n\nreturn " . var_export($config, true) . ";");
            }
        } else {
            return $is_ajax ? $this->error($ip . __('Authorization check failed')) : sysmsg($ip . __('Authorization check failed'));
        }
    }
}