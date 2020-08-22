function EchoMsg(msg,auto,local,time=1000){
    $.alert({
        title: '温馨提示',
        content: msg,
        buttons: {
            okay: {
                text: '确认',
                btnClass: 'btn-blue'
            }
        }
    });
    if(auto){
        if(local){
            setTimeout("window.location.href='"+local+"'", time);
        }else{
            setTimeout("location.reload();", time);
        }
    }
}