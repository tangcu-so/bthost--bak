<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use app\common\library\Btaction;
use dnspod\Dnspod;
use fast\Random;
use think\Db;

/**
 * 主机管理
 *
 * @icon fa fa-circle-o
 */
class Host extends Backend
{
    
    /**
     * Host模型对象
     * @var \app\admin\model\Host
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\Host;
        $this->view->assign("isVsftpdList", $this->model->getIsVsftpdList());
        $this->view->assign("statusList", $this->model->getStatusList());
    }

    public function import()
    {
        parent::import();
    }

    /**
     * 查看
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = $this->model
                ->with('user')
                ->where($where)
                ->order($sort, $order)
                ->count();
            $list = $this->model
                ->with('user')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            foreach ($list as $k => $v) {
                // 关联分类
                $v->sort_id = $this->model->sort($v->sort_id);
                
                $v->hidden(['user.password', 'user.salt']);
            }
            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 默认生成的控制器所继承的父类中有index/add/edit/del/multi五个基础方法、destroy/restore/recyclebin三个回收站方法
     * 因此在当前控制器中可不用编写增删改查的代码,除非需要自己控制这部分逻辑
     * 需要将application/admin/library/traits/Backend.php中对应的方法复制到当前控制器,然后进行修改
     */
    
    public function add(){
        if($this->request->isPost()){
            $params = $this->request->post('row/a');
            // try {
                // 读取资源组
                // 资源组信息转化
                $plansInfo = model('Plans')->getPlanInfo($params['plans']);
                if(!$plansInfo){
                    $this->error(model('Plans')->msg);
                }
                // var_dump($plansInfo);exit;
                $bt = new Btaction();
                
                $hostSetInfo = $bt->setInfo($params,$plansInfo);
                // 连接宝塔进行站点开通
                $btInfo = $bt->btBuild($hostSetInfo);
                if (!$btInfo) {
                    $this->error($bt->_error);
                }
                $bt->bt_id = $btId = $btInfo['siteId'];
                $btName = $hostSetInfo['bt_name'];
                

                Db::startTrans();

                
                // vsftpd创建
                
                // 修改到期时间
                $timeSet = $bt->btAction->WebSetEdate($btId,$params['endtime']);
                if (!$timeSet['status']) {
                    $this->error('开通时间设置失败|' . json_encode($params['endtime']));
                }
                
                // 预装程序
                if($plansInfo['preset_procedure']){
                    // 程序预装
                    $defaultPhp = $hostSetInfo['version']&&$hostSetInfo['version']!='00'?$hostSetInfo['version']:'56';
                    $setUp = $bt->presetProcedure($plansInfo['preset_procedure'], $btName, $defaultPhp);
                    if(!$setUp){
                        $this->error($bt->_error);
                    }
                }
                // session隔离
                // 并发、限速设置
                // 默认并发、网速限制
                if (isset($plansInfo['perserver']) && $plansInfo['perserver'] != 0&&isset($bt->btTend->serverConfig['webserver'])&&$bt->btTend->serverConfig['webserver']=='nginx') {
                    // 有错误，记录，防止开通被打断
                    $modify_status = $bt->setLimit($plansInfo);
                    if (!$modify_status) {
                        $this->error($bt->_error);
                    }
                }
                
                // dnspod智能解析
                if($plansInfo['dnspod']){
                    // var_dump($plansInfo['ip']);exit;
                    $sub_domain = $hostSetInfo['domain'];
                    $domain_jx = $this->model->doamin_analysis($plansInfo['domain'],$plansInfo['ip'],$sub_domain);
                    if(!is_array($domain_jx)){
                        $this->error('域名解析失败|' . json_encode([$plansInfo['domain'],$plansInfo['ip'],$sub_domain,$domain_jx],JSON_UNESCAPED_UNICODE));
                    }
                    $dnspod_record = $sub_domain;
                    $dnspod_record_id = $domain_jx['id'];
                    $dnspod_domain_id = $domain_jx['domain_id'];
                }else{
                    $dnspod_record = '';
                    $dnspod_record_id = '';
                    $dnspod_domain_id = '';
                }
                
                // 绑定多ip
                
                // 获取信息后存入数据库
                $inc = model('Host')::create([
                    'user_id'               => $params['user_id'],
                    'sort_id'               => $params['sort_id'],
                    'bt_id'                 => $btId,
                    'bt_name'               => $btName,
                    'site_max'              => $plansInfo['site_max'],
                    'sql_max'               => $plansInfo['sql_max'],
                    'flow_max'              => $plansInfo['flow_max'],
                    'analysis_type'         => $plansInfo['analysis_type'],
                    'default_analysis'      => $plansInfo['default_analysis'],
                    'is_audit'              => $plansInfo['domain_audit'],
                    'is_vsftpd'             => $plansInfo['vsftpd'],
                    'domain_max'            => $plansInfo['domain_num'],
                    'web_back_num'          => $plansInfo['web_back_num'],
                    'sql_back_num'          => $plansInfo['sql_back_num'],
                    'ip_address'            => $plansInfo['ip'],
                    'endtime'               => $params['endtime'],
                ]);

                

                $vhost_id = $inc->id;
                if(!$vhost_id){
                    $this->error('主机信息存储失败');
                }
                
                if($btInfo['ftpStatus']==true){
                    // 存储ftp
                    $ftp = model('Ftp')::create([
                        'vhost_id'=>$vhost_id,
                        'username'=>$btInfo['ftpUser'],
                        'password'=>$btInfo['ftpPass'],
                    ]);
                }
                
                if($btInfo['databaseStatus']==true){
                    // 存储sql
                    $sql = model('Sql')::create([
                        'vhost_id'=>$vhost_id,
                        'username'=>$btInfo['databaseUser'],
                        'password'=>$btInfo['databasePass'],
                    ]);
                }

                // IP池地址占用
                model('Ipaddress')->where(['ip'=>$plansInfo['ip'],'ippools_id'=>$plansInfo['ippools_id']])->update(['vhost_id'=>$vhost_id]);
                // 存入域名信息
                model('domain')::create([
                    'domain'=>$btName,
                    'vhost_id'=>$vhost_id,
                    'domainlist_id'=>$plansInfo['domainlist_id'],
                    'dnspod_record'=>$dnspod_record,
                    'dnspod_record_id'=>$dnspod_record_id,
                    'dnspod_domain_id'=>$dnspod_domain_id,
                    'dir'=>'/',
                ]);
                
                Db::commit();
            // } catch (\Exception $ex) {
            //     return ['code'=>0,'msg'=>$ex->getMessage()];
            // } catch (\Throwable $th) {
            //     return ['code'=>0,'msg'=>$th->getMessage()];
            // }
            
            $this->success('添加成功');
        }
        return $this->view->fetch();
    }

    // 真实删除
    public function destroy($ids = null){
        // 获取数据信息
        $info = $this->model::onlyTrashed()->where(['id'=>$ids])->find();
        if(!$info){
            $this->error('数据不存在');
        }
        if($info->bt_id&&$info->bt_name){
            // 连接服务器删除站点
            $bt = new Btaction();
            $del = $bt->siteDelete($info->bt_id,$info->bt_name);
            if(!$del){
                $this->error($bt->_error);
            }
        }
        if($info->is_vsftpd){
            // 如果有开通vsftpd，也删除
            // 暂时没有api，后续更新
        }
        if($info->user_id){
            model('User')->where('id',$info->user_id)->delete(true);
        }
        // 连接数据库删除相关数据
        
        parent::destroy($ids);
    }
}