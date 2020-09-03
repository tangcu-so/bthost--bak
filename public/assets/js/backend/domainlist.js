define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'domainlist/index' + location.search,
                    add_url: 'domainlist/add',
                    edit_url: 'domainlist/edit',
                    del_url: 'domainlist/del',
                    multi_url: 'domainlist/multi',
                    import_url: 'domainlist/import',
                    table: 'domainlist',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id')},
                        {field: 'domain', title: __('Domain'),formatter: function (value, row, index) { 
                            return '<a href="http://'+row.domain+'" target="_blank"  title="点击访问">' + row.domain + '</a>';
                    }},
                        {field: 'vhost.bt_name', title: __('Host_id')},
                        {field: 'doma.domain', title: __('Domain_id')},
                        {field: 'dnspod_record', title: __('Dnspod_record')},
                        // {field: 'dnspod_record_id', title: __('Dnspod_record_id')},
                        // {field: 'dnspod_domain_id', title: __('Dnspod_domain_id')},
                        {field: 'dir', title: __('Dir')},
                        {field: 'audit', title: __('Audit'), searchList: {"0":__('Audit 0'),"1":__('Audit 1'),"2":__('Audit 2')}, formatter: Table.api.formatter.normal},
                        {field: 'createtime', title: __('Createtime'), operate:'RANGE', addclass:'datetimerange', formatter: Table.api.formatter.datetime},
                        {field: 'updatetime', title: __('Updatetime'), operate:'RANGE', addclass:'datetimerange', formatter: Table.api.formatter.datetime},
                        {field: 'status', title: __('Status'), searchList: {"normal":__('Normal'),"hidden":__('Hidden')}, formatter: Table.api.formatter.status},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate,
                        buttons:[
                            {
                                name: 'ajax',
                                text: __('审核'),
                                title: __('审核'),
                                classname: 'btn btn-xs btn-info btn-ajax',
                                icon: 'fa fa-magic',
                                url: 'domainlist/audit',
                                refresh:true,
                                confirm: '审核并绑定该域名？',
                                success: function (data, ret) {
                                    table.bootstrapTable('refresh');
                                },
                                error: function (data, ret) {
                                    console.log(data, ret);
                                    Layer.alert(ret.msg);
                                    return false;
                                }
                            },
                        ]}
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        recyclebin: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    'dragsort_url': ''
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: 'domainlist/recyclebin' + location.search,
                pk: 'id',
                sortName: 'id',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id')},
                        {field: 'domain', title: __('Domain')},
                        {field: 'vhost.bt_name', title: __('Vhost_id')},
                        {
                            field: 'deletetime',
                            title: __('Deletetime'),
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'operate',
                            width: '130px',
                            title: __('Operate'),
                            table: table,
                            events: Table.api.events.operate,
                            buttons: [
                                {
                                    name: 'Restore',
                                    text: __('Restore'),
                                    classname: 'btn btn-xs btn-info btn-ajax btn-restoreit',
                                    icon: 'fa fa-rotate-left',
                                    url: 'domainlist/restore',
                                    refresh: true
                                },
                                {
                                    name: 'Destroy',
                                    text: __('Destroy'),
                                    classname: 'btn btn-xs btn-danger btn-ajax btn-destroyit',
                                    icon: 'fa fa-times',
                                    url: 'domainlist/destroy',
                                    refresh: true
                                }
                            ],
                            formatter: Table.api.formatter.operate
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        add: function () {
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});