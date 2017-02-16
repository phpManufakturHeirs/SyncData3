var est = 0; // time estimated

function sd3_setError(message) {
    $("#error > div").html(message).show();
    $("#wait").remove();
    $("#progress").remove();
    $("div#beat").remove();
    est = maxwait; // finalize poll
}

(function sd3_poll() {
    setTimeout(function() {
        $.ajax({
            url: url + "/poll?jobid=" + jobid,
            dataType: "json",
        }).always(function(jqXHR,textStatus,jqObj) {
            if(textStatus=='success') {
                if(typeof jqObj.responseJSON !== 'object') {
                    var data = $.parseJSON(jqObj.responseJSON);
                } else {
                    var data = jqObj.responseJSON;
                }
                if(data.message) {
                    $("#progress > div").html(data.message);
                }
                if(!data.success || data.success === false) {
                    if(data.errors) {
                        $("#error > div").html(data.errors).parent().show();
                    } else {
                        $("#error > div").html(data.message).show();
                    }
                }
                if(est < maxwait && !data.finished) {
                    sd3_poll();
                } else {
                    $("div.la-ball-beat").remove();
                    $("div#wait").remove();
                }
                est = est + interval;
                $('span#secs').text(est/1000);
            }
        });
    }, interval);
})();

$.ajax({
    dataType: "json",
    url: url + "/autosync_exec?jobid=" + jobid
}).fail(function(jqXHR, textStatus, errorThrown) {
    sd3_setError("/autosync_exec<br /><br />Aktualisierung fehlgeschlagen." + "<br /><br />" + textStatus);
});