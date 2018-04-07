<?php
/**
 * 前台商品
 *
 *
 *
 *
 * @copyright  Copyright (c) 2007-2018 ShopNC Inc. (http://www.shopnc.net)
 * @license    http://www.shopnc.net
 * @link       http://www.shopnc.net
 * @since      File available since Release v1.1
 */
use Shopnc\Tpl;


defined('InShopNC') or exit('Access Invalid!');

if(preg_match("/mobile|nokia|iphone|ipad|android|samsung|htc|blackberry/i", $_SERVER['HTTP_USER_AGENT']) && empty($_COOKIE['pc'])) {
    @header('Location: '.C('wap_site_url').'/tmpl/product_detail.html?goods_id='.intval($_GET['goods_id']));exit;
}
class goodsControl extends BaseGoodsControl {
    public function __construct() {
        parent::__construct ();
        Language::read('store_goods_index');
    }

    /**
     * 单个商品信息页
     */
    public function indexOp() {
        $goods_id = intval($_GET['goods_id']);

        // 商品详细信息
        $model_goods = Model('goods');
        $goods_detail = $model_goods->getGoodsDetail($goods_id);
        $goods_info = $goods_detail['goods_info'];
        if (empty($goods_info)) {
            showMessage(L('goods_index_no_goods'), '', 'html', 'error');
        }

        $this->getStoreInfo($goods_info['store_id'], $goods_info);

        //会员等级价格
        $goods_info = Logic('goods')->getGoodsMemberPrice($goods_info, $this->member_info);

        // 看了又看（同分类本店随机商品）
        $size = $goods_info['is_own_shop'] ? 8 : 4;
        $goods_rand_list = $model_goods->getGoodsGcStoreRandList($goods_info['gc_id_1'], $goods_info['store_id'], $goods_info['goods_id'], $size);
        Tpl::output('goods_rand_list', $goods_rand_list);

        Tpl::output('spec_list', $goods_detail['spec_list']);
        Tpl::output('spec_image', $goods_detail['spec_image']);
        Tpl::output('image_list', $goods_detail['image_list']);
        Tpl::output('image_json' , json_encode($goods_detail['image_list']));
        Tpl::output('goods_image', $goods_detail['goods_image']);
        Tpl::output('goods_video', $goods_detail['video_path']);
        Tpl::output('mansong_info', $goods_detail['mansong_info']);
        Tpl::output('gift_array', $goods_detail['gift_array']);

        // 浏览过的商品
        $viewed_goods = Model('goods_browse')->getViewedGoodsList($_SESSION['member_id'], 20);
        Tpl::output('viewed_goods', $viewed_goods);
            // 查询SNS中该商品的信息
            $snsgoodsinfo = Model('sns_goods')->getSNSGoodsInfo(array('snsgoods_goodsid' => $goods_info['goods_id']), 'snsgoods_likenum,snsgoods_sharenum');
            $_cache = array();
            $_cache['likenum'] = $snsgoodsinfo['snsgoods_likenum'];
            $_cache['sharenum'] = $snsgoodsinfo['snsgoods_sharenum'];
        $goods_info = array_merge($goods_info, $_cache);

        $inform_switch = true;
        // 检测商品是否下架,检查是否为店主本人
        if ($goods_info['goods_state'] != 1 || $goods_info['goods_verify'] != 1 || $goods_info['store_id'] == $_SESSION['store_id']) {
            $inform_switch = false;
        }
        Tpl::output('inform_switch',$inform_switch );

        // 如果使用运费模板
        if ($goods_info['transport_id'] > 0) {
            // 取得三种运送方式默认运费
            $model_transport = Model('transport');
            $transport = $model_transport->getExtendList(array('transport_id' => $goods_info['transport_id']));
            if (!empty($transport) && is_array($transport)) {
                foreach ($transport as $v) {
                    $goods_info[$v['type'] . "_price"] = $v['sprice'];
                }
            }
        }
        // 验证预定商品是否到期
        if ($goods_info['is_book'] == 1) {
            if ( $goods_info['book_down_time'] < TIMESTAMP ) {
                QueueClient::push('updateGoodsPromotionPriceByGoodsId', $goods_info['goods_id']);
                $goods_info['is_book'] = 0;
            } else {
                $remain_time = intval($goods_info['book_down_time'])- TIMESTAMP; //剩余的时间
                $remain_day = floor($remain_time/(60*60*24)); //剩余的天数
                $remain_hour = floor(($remain_time - $remain_day*60*60*24)/(60*60)); //剩余的小时数
                $remain_minute = floor(($remain_time - $remain_day*60*60*24 - $remain_hour*60*60)/60); //剩余的分钟数
                Tpl::output('remain', array('day' => $remain_day, 'hour' => $remain_hour, 'minute' => $remain_minute));
            }
        }
        //商品批发处理
        if($goods_info['is_batch'] == 1){
            Tpl::output('batch_price' , $goods_info['batch_price']);
        }
        //处理商品消费者保障服务信息
        $goods_list = $model_goods->getGoodsContract(array(0=>$goods_info));
        $goods_info = $goods_list[0];
        Tpl::output('goods', $goods_info);
        Tpl::output('store_id', $goods_info['store_id']);
        
        //推荐商品
        $goods_commend_list = $model_goods->getGoodsCommendList($goods_info['store_id'], 5);
        Tpl::output('goods_commend',$goods_commend_list);

        // 当前位置导航
        $nav_link_list = Model('goods_class')->getGoodsClassNav($goods_info['gc_id'], 0);
        $nav_link_list[] = array('title' => $goods_info['goods_name']);
        Tpl::output('nav_link_list', $nav_link_list);

        //评价信息
        $goods_evaluate_info = Model('evaluate_goods')->getEvaluateGoodsInfoByGoodsID($goods_id,$goods_info);
        Tpl::output('goods_evaluate_info', $goods_evaluate_info);

        $seo_param = array();
        $seo_param['name'] = $goods_info['goods_name'];
        $seo_param['key'] = $goods_info['goods_keywords'];
        $seo_param['description'] = $goods_info['goods_description'];
        Model('seo')->type('product')->param($seo_param)->show();
        Tpl::showpage('goods');
    }
    /**
     * 记录浏览历史
     */
    public function addbrowseOp(){
        $goods_id = intval($_GET['gid']);
        if (!$_SESSION['member_id']) {
            Model('goods_browse')->addViewedGoodsToCookie($goods_id);exit();
        }
        QueueClient::push('addViewedGoods', array('goods_id'=>$goods_id,'member_id'=>$_SESSION['member_id'],'store_id'=>$_SESSION['store_id']));
        exit();
    }

    /**
     * 商品评论
     */
    public function commentsOp() {
        $goods_id = intval($_GET['goods_id']);
        $this->_get_comments($goods_id, $_GET['type'], 10);
        Tpl::showpage('goods.comments','null_layout');
    }

    /**
     * 商品评价详细页
     */
    public function comments_listOp() {
        $goods_id = intval($_GET['goods_id']);

        // 商品详细信息
        $model_goods = Model('goods');
        $goods_info = $model_goods->getGoodsInfoByID($goods_id, '*');
        // 验证商品是否存在
        if (empty($goods_info)) {
            showMessage(L('goods_index_no_goods'), '', 'html', 'error');
        }
        Tpl::output('goods', $goods_info);

        $this->getStoreInfo($goods_info['store_id']);

        // 当前位置导航
        $nav_link_list = Model('goods_class')->getGoodsClassNav($goods_info['gc_id'], 0);
        $nav_link_list[] = array('title' => $goods_info['goods_name'], 'link' => urlShop('goods', 'index', array('goods_id' => $goods_id)));
        $nav_link_list[] = array('title' => '商品评价');
        Tpl::output('nav_link_list', $nav_link_list );

        //评价信息
        $goods_evaluate_info = Model('evaluate_goods')->getEvaluateGoodsInfoByGoodsID($goods_id,$goods_info);
        Tpl::output('goods_evaluate_info', $goods_evaluate_info);

        $seo_param = array ();

        $seo_param['name'] = $goods_info['goods_name'];
        $seo_param['key'] = $goods_info['goods_keywords'];
        $seo_param['description'] = $goods_info['goods_description'];
        Model('seo')->type('product')->param($seo_param)->show();

        $this->_get_comments($goods_id, $_GET['type'], 20);

        Tpl::showpage('goods.comments_list');
    }

    private function _get_comments($goods_id, $type, $page) {
        $condition = array();
        $condition['geval_goodsid'] = $goods_id;
        switch ($type) {
            case '1':
                $condition['geval_scores'] = array('in', '5,4');
                Tpl::output('type', '1');
                break;
            case '2':
                $condition['geval_scores'] = array('in', '3,2');
                Tpl::output('type', '2');
                break;
            case '3':
                $condition['geval_scores'] = array('in', '1');
                Tpl::output('type', '3');
                break;
            case '4':
                $condition['geval_image|geval_image_again'] = array('neq', '');
                break;
        }

        //查询商品评分信息
        $model_evaluate_goods = Model("evaluate_goods");
        $goodsevallist = $model_evaluate_goods->getEvaluateGoodsList($condition, $page);
        Tpl::output('goodsevallist',$goodsevallist);
        Tpl::output('show_page',$model_evaluate_goods->showpage('5'));
    }

    /**
     * 产品咨询
     */
    public function consultingOp() {
        $goods_id = intval($_GET['goods_id']);
        if($goods_id <= 0){
            showMessage(Language::get('wrong_argument'),'','html','error');
        }

        //得到商品咨询信息
        $model_consult = Model('consult');
        $where = array();
        $where['goods_id'] = $goods_id;
        if (intval($_GET['ctid']) > 0) {
            $where['ct_id'] = intval($_GET['ctid']);
        }
        $consult_list = $model_consult->getConsultList($where,'*','10');
        Tpl::output('consult_list',$consult_list);

        // 咨询类型
        $consult_type = rkcache('consult_type', true);
        Tpl::output('consult_type', $consult_type);

        Tpl::output('consult_able',$this->checkConsultAble());
        Tpl::showpage('goods.consulting', 'null_layout');
    }

    /**
     * 产品咨询
     */
    public function consulting_listOp() {
        Tpl::output('hidden_nctoolbar', 1);
        $goods_id    = intval($_GET['goods_id']);
        if($goods_id <= 0){
            showMessage(Language::get('wrong_argument'),'','html','error');
        }

        // 商品详细信息
        $model_goods = Model('goods');
        $goods_info = $model_goods->getGoodsInfoByID($goods_id, '*');
        // 验证商品是否存在
        if (empty($goods_info)) {
            showMessage(L('goods_index_no_goods'), '', 'html', 'error');
        }
        Tpl::output('goods', $goods_info);

        $this->getStoreInfo($goods_info['store_id']);

        // 当前位置导航
        $nav_link_list = Model('goods_class')->getGoodsClassNav($goods_info['gc_id'], 0);
        $nav_link_list[] = array('title' => $goods_info['goods_name'], 'link' => urlShop('goods', 'index', array('goods_id' => $goods_id)));
        $nav_link_list[] = array('title' => '商品咨询');
        Tpl::output('nav_link_list', $nav_link_list);

        //得到商品咨询信息
        $model_consult = Model('consult');
        $where = array();
        $where['goods_id'] = $goods_id;
        if (intval($_GET['ctid']) > 0) {
            $where['ct_id']  = intval($_GET['ctid']);
        }
        $consult_list = $model_consult->getConsultList($where, '*', 0, 20);
        Tpl::output('consult_list',$consult_list);
        Tpl::output('show_page', $model_consult->showpage());

        // 咨询类型
        $consult_type = rkcache('consult_type', true);
        Tpl::output('consult_type', $consult_type);

        $seo_param = array ();
        $seo_param['name'] = $goods_info['goods_name'];
        $seo_param['key'] = $goods_info['goods_keywords'];
        $seo_param['description'] = $goods_info['goods_description'];
        Model('seo')->type('product')->param($seo_param)->show();

        Tpl::output('consult_able',$this->checkConsultAble($goods_info['store_id']));
        Tpl::showpage('goods.consulting_list');
    }

    private function checkConsultAble( $store_id = 0) {
        //检查是否为店主本身
        $store_self = false;
        if(!empty($_SESSION['store_id'])) {
            if (($store_id == 0 && intval($_GET['store_id']) == $_SESSION['store_id']) || ($store_id != 0 && $store_id == $_SESSION['store_id'])) {
                $store_self = true;
            }
        }
        //查询会员信息
        $member_info    = array();
        $member_model = Model('member');
        if(!empty($_SESSION['member_id'])) $member_info = $member_model->getMemberInfoByID($_SESSION['member_id'],'is_allowtalk');
        //检查是否可以评论
        $consult_able = true;
        if((!C('guest_comment') && !$_SESSION['member_id'] ) || $store_self == true || ($_SESSION['member_id']>0 && $member_info['is_allowtalk'] == 0)){
            $consult_able = false;
        }
        return $consult_able;
    }

    /**
     * 商品咨询添加
     */
    public function save_consultOp(){
        //检查是否可以评论
        if(!C('guest_comment') && !$_SESSION['member_id']){
            showDialog(L('goods_index_goods_noallow'));
        }
        $goods_id    = intval($_POST['goods_id']);
        if($goods_id <= 0){
            showDialog(L('wrong_argument'));
        }
        //咨询内容的非空验证
        if(trim($_POST['goods_content'])== ""){
            showDialog(L('goods_index_input_consult'));
        }
        //表单验证
        $result = chksubmit(true,C('captcha_status_goodsqa'),'num');
        if (!$result){
            showDialog(L('invalid_request'));
        } elseif ($result === -11){
            showDialog(L('invalid_request'));
        }elseif ($result === -12){
            showDialog(L('wrong_checkcode'));
        }
        if (process::islock('commit')){
            showDialog(L('nc_common_op_repeat'));
        }else{
            process::addprocess('commit');
        }
        if($_SESSION['member_id']){
            //查询会员信息
            $member_model = Model('member');
            $member_info = $member_model->getMemberInfo(array('member_id'=>$_SESSION['member_id']));
            if(empty($member_info) || $member_info['is_allowtalk'] == 0){
                showDialog(L('goods_index_goods_noallow'));
            }
        }
        //判断商品编号的存在性和合法性
        $goods = Model('goods');
        $goods_info = $goods->getGoodsInfoByID($goods_id, 'goods_name,store_id');
        if(empty($goods_info)){
            showDialog(L('goods_index_goods_not_exists'));
        }
        //判断是否是店主本人
        if($_SESSION['store_id'] && $goods_info['store_id'] == $_SESSION['store_id']) {
            showDialog(L('goods_index_consult_store_error'));
        }
        //检查店铺状态
        $store_model = Model('store');
        $store_info = $store_model->getStoreInfoByID($goods_info['store_id']);
        if($store_info['store_state'] == '0' || intval($store_info['store_state']) == '2' || (intval($store_info['store_end_time']) != 0 && $store_info['store_end_time'] <= time())){
            showDialog(L('goods_index_goods_store_closed'));
        }
        //接收数据并保存
        $input  = array();
        $input['goods_id']          = $goods_id;
        $input['goods_name']        = $goods_info['goods_name'];
        $input['member_id']         = intval($_SESSION['member_id']) > 0?$_SESSION['member_id']:0;
        $input['member_name']       = $_SESSION['member_name']?$_SESSION['member_name']:'';
        $input['store_id']          = $store_info['store_id'];
        $input['store_name']        = $store_info['store_name'];
        $input['ct_id']             = intval($_POST['consult_type_id']);
        $input['consult_addtime']   = TIMESTAMP;
        if (strtoupper(CHARSET) == 'GBK') {
            $input['consult_content']   = Language::getGBK($_POST['goods_content']);
        }else{
            $input['consult_content']   = $_POST['goods_content'];
        }
        $input['isanonymous']       = $_POST['hide_name']=='hide'?1:0;
        $consult_model  = Model('consult');
        if($consult_model->addConsult($input)){
            showDialog(L('goods_index_consult_success'), 'reload', 'succ');
        }else{
            showDialog(L('goods_index_consult_fail'));
        }
    }

    /**
     * 异步显示优惠套装/推荐组合
     */
    public function get_bundlingOp() {
        $goods_id = intval($_GET['goods_id']);
        if ($goods_id <= 0) {
            exit();
        }
        $model_goods = Model('goods');
        $goods_info = $model_goods->getGoodsOnlineInfoByID($goods_id);
        if (empty($goods_info)) {
            exit();
        }

        // 优惠套装
        $array = Model('p_bundling')->getBundlingCacheByGoodsId($goods_id);
        if (!empty($array)) {
            Tpl::output('bundling_array', unserialize($array['bundling_array']));
            Tpl::output('b_goods_array', unserialize($array['b_goods_array']));
        }

        // 推荐组合
        if (!empty($goods_info) && $model_goods->checkIsGeneral($goods_info)) {
            $array = Model('p_combo_goods')->getComboGoodsCacheByGoodsId($goods_id);
            Tpl::output('goods_info', $goods_info);
            Tpl::output('gcombo_list', unserialize($array['gcombo_list']));
        }

        Tpl::showpage('goods_bundling', 'null_layout');
    }

    /**
     * 商品详细页运费显示
     *
     * @return unknown
     */
    public function calcOp(){
        if (!is_numeric($_GET['area_id']) || !is_numeric($_GET['tid'])) return false;
        $goods_id = intval($_GET['goods_id']);
        $goods_n = 1;
        if (intval($_GET['num'])) $goods_n = intval($_GET['num']);
        $goods_info = Model('goods')->getGoodsInfo(array('goods_id'=>$goods_id),'transport_id,store_id,goods_freight,goods_storage,goods_trans_v');
        $goods_info['goods_n'] = $goods_n;
        $freight_total = Model('transport')->goods_trans_calc($goods_info,intval($_GET['area_id']));
        if ($freight_total > 0) {
            if ($_GET['myf'] > 0) {
                if (floatval($_GET['goods_price']) * $goods_n >= $_GET['myf']) {
                    $freight_total = '免运费';
                } else {
                    $freight_total = '运费：'.$freight_total.' 元，店铺满 '.$_GET['myf'].' 元 免运费';
                }
            } else {
                $freight_total = '运费：'.$freight_total.' 元';
            }
        } else {
            if ($freight_total !== false) {
                $freight_total = '免运费';
            }
        }
        echo $_GET['callback'].'('.json_encode(array('total'=>$freight_total)).')';
    }

    /**
     * 到货通知
     */
    public function arrival_noticeOp() {
        if (!$_SESSION['is_login'] ){
            showMessage(L('wrong_argument'), '', '', 'error');
        }
        $member_info = Model('member')->getMemberInfoByID($_SESSION['member_id'], 'member_email,member_mobile');
        Tpl::output('member_info', $member_info);

        Tpl::showpage('arrival_notice.submit', 'null_layout');
    }

    /**
     * 到货通知表单
     */
    public function arrival_notice_submitOp() {
        $type = intval($_POST['type']) == 2 ? 2 : 1;
        $goods_id = intval($_POST['goods_id']);
        if ($goods_id <= 0) {
            showDialog(L('wrong_argument'), 'reload');
        }
        // 验证商品数是否充足
        $goods_info = Model('goods')->getGoodsInfoByID($goods_id, 'goods_id,goods_name,goods_storage,goods_state,store_id');
        if (empty($goods_info) || ($goods_info['goods_storage'] > 0 && $goods_info['goods_state'] == 1)) {
            showDialog(L('wrong_argument'), 'reload');
        }

        $model_arrivalnotice = Model('arrival_notice');
        // 验证会员是否已经添加到货通知
        $where = array();
        $where['goods_id'] = $goods_info['goods_id'];
        $where['member_id'] = $_SESSION['member_id'];
        $where['an_type'] = $type;
        $notice_info = $model_arrivalnotice->getArrivalNoticeInfo($where);
        if (!empty($notice_info)) {
            if ($type == 1) {
                showDialog('您已经添加过通知提醒，请不要重复添加', 'reload');
            } else {
                showDialog('您已经预约过了，请不要重复预约', 'reload');
            }
        }

        $insert = array();
        $insert['goods_id'] = $goods_info['goods_id'];
        $insert['goods_name'] = $goods_info['goods_name'];
        $insert['member_id'] = $_SESSION['member_id'];
        $insert['store_id'] = $goods_info['store_id'];
        $insert['an_mobile'] = $_POST['mobile'];
        $insert['an_email'] = $_POST['email'];
        $insert['an_type'] = $type;
        $model_arrivalnotice->addArrivalNotice($insert);

        $title = $type == 1 ? '到货通知' : '立即预约';
        $js = "ajax_form('arrival_notice', '". $title ."', '" . urlShop('goods', 'arrival_notice_succ', array('type' => $type)) . "', 480);";
        showDialog('','','js',$js);
    }

    /**
     * 到货通知添加成功
     */
    public function arrival_notice_succOp() {
        // 可能喜欢的商品
        $goods_list = Model('goods_browse')->getGuessLikeGoods($_SESSION['member_id'], 4);
        Tpl::output('goods_list', $goods_list);
        Tpl::showpage('arrival_notice.message', 'null_layout');
    }
    
    /**
     * 显示门店
     */
    public function show_chainOp() {
        $model_chain = Model('chain');
        $model_chain_stock = Model('chain_stock');
        $goods_id = $_GET['goods_id'];
        $stock_list = $model_chain_stock->getChainStockList(array('goods_id' => $goods_id, 'stock' => array('gt', 0), 'chain_price' => array('gt', 0)), 'chain_id,chain_price');
        if (!empty($stock_list)) {
            $chainid_array = array();
            $price_list = array();
            foreach ($stock_list as $val) {
                $chainid_array[] = $val['chain_id'];
                $price_list[$val['chain_id']] = $val['chain_price'];
            }
            $chain_array = $model_chain->getChainList(array('chain_id' => array('in', $chainid_array), 'chain_state' => 1, 'is_self_take' => 1));
            $chain_list = array();
            if (!empty($chain_array)) {
                $model_chain_voucher = Model('chain_voucher');
                $condition = array();
                $condition['voucher_t_chain_id'] = array('in', $chainid_array);
                $condition['voucher_t_state'] = 1;
                $condition['voucher_t_end_date'] = array('gt',TIMESTAMP);
                $field = "count(voucher_t_id) as num,voucher_t_chain_id";
                $voucher_list = $model_chain_voucher->getVoucherTemplateList($condition, $field, 0, 0, '','voucher_t_chain_id');
                $voucher_list = array_under_reset($voucher_list, 'voucher_t_chain_id');
                foreach ($chain_array as $val) {
                    $val['voucher_num'] = 0;
                    $chain_id = $val['chain_id'];
                    if (!empty($voucher_list[$chain_id])) $val['voucher_num'] = $voucher_list[$chain_id]['num'];
                    $val['shopnc_chain_price'] = $price_list[$chain_id];
                    $chain_list[$val['area_id']][] = $val;
                }
            }
            
            Tpl::output('chain_list', json_encode($chain_list));
        }
        Tpl::showpage('goods.show_chain', 'null_layout');
    }
}