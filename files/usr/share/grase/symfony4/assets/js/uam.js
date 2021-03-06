const $ = require('jquery');

require('./translations');

import ChilliMD5 from './chilliMD5';
import { getQueryVariable } from './utils';
require('bootstrap');
//require('admin-lte');


let chilliController = {
    stateCodes: {
        UNKNOWN: -1,
        NOT_AUTH: 0,
        AUTH: 1,
        AUTH_PENDING: 2,
        AUTH_SPLASH: 3
    },
    clientState: -1,
    challenge: null,
    loginType: null,
    timeoutvar: null,
    ident: '00',
};

// TODO make this dynamic (it could be the uam server, or it could be a different server, depending on the setup)
chilliController.urlRoot = 'http://' + window.location.hostname + ':' + 3990 + '/json/';

chilliController.formatTime = function (t, zeroReturn) {

    if (typeof (t) == 'undefined') {
        return "Not available";
    }

    t = parseInt(t, 10);
    if ((typeof (zeroReturn) != 'undefined') && (t === 0)) {
        return zeroReturn;
    }
    if ((typeof (zeroReturn) != 'undefined') && (t < 0)) {
        return zeroReturn;
    }

    const h = Math.floor(t / 3600);
    const m = Math.floor((t - 3600 * h) / 60);
    const s = t % 60;

    let s_str = s.toString();
    if (s < 10) {
        s_str = '0' + s_str;
    }

    let m_str = m.toString();
    if (m < 10) {
        m_str = '0' + m_str;
    }

    let h_str = h.toString();
    if (h < 10) {
        h_str = '0' + h_str;
    }

    if (t < 60) {
        return s_str + 's';
    } else if (t < 3600) {
        return m_str + 'm ' + s_str + 's';
    } else {
        return h_str + 'h ' + m_str + 'm ' + s_str + 's';
    }

};

chilliController.formatBytes = function (b, zeroReturn) {

    if (typeof (b) == 'undefined') {
        b = 0;
    } else {
        b = parseInt(b, 10);
    }

    if ((typeof (zeroReturn) != 'undefined') && (b === 0)) {
        return zeroReturn;
    }

    const kb = Math.round(b / 10.24) / 100;
    if (kb < 1) return b + ' b';

    const mb = Math.round(kb / 10.24) / 100;
    if (mb < 1) return kb + ' Kb';

    const gb = Math.round(mb / 10.24) / 100;
    if (gb < 1) return mb + ' Mb';

    return gb + ' Gb';
};


chilliController.getChallenge = function () {
    if (typeof (self.challenge) != 'string') {
        ajaxChilliController('status')
            .done(function (resp) {
                    // Check for valid challenge

                    if (typeof (resp.challenge) != 'string') {
                        clearErrorMessages();
                        display_error(Translator.trans('uam.js.error.secure_challenge_failure'));
                        return false;
                    }
                    if (resp.clientState === chilliController.stateCodes.AUTH) {
                        pageStates.loggedInFormState();
                        clearErrorMessages();
                        error_message(Translator.trans('uam.js.error.already_logged_in'));

                        return false;
                    }
                    // Check clientState

                    /// ...

                    // Got valid challenge and not logged in
                    chilliController.challenge = resp.challenge;

                    chilliController.getLogin();

                })
            .fail(function () {
                    clearErrorMessages();
                    display_error(Translator.trans("uam.js.server_timed_out"));
                }
            )
    } else {
        chilliController.getLogin();
    }
}

chilliController.getLogin = function () {
    // Redirect to the TOS login functions when it's a TOS login
    if (chilliController.loginType === "TOS") {
        chilliController.tosGetResponse();
        return false;
    }
    /* Calculate MD5 CHAP at the client side */
    const myMD5 = new ChilliMD5();

    const password = $("#password").val();
    const username = $("#username").val();

    if (typeof (password) !== 'string' || typeof (username) !== 'string' || password.length === 0 || username.length === 0) {
        display_error(Translator.trans('uam.js.both_username_password_needed'));
        return false;
    }

    const chappassword = myMD5.chap(chilliController.ident, password, chilliController.challenge);

    /* Build /logon command URL */
    const logonPath = 'logon?username=' + encodeURIComponent(username) + '&response=' + encodeURIComponent(chappassword);

    chilliController.clientState = chilliController.stateCodes.AUTH_PENDING;

    ajaxChilliController(logonPath)
        .done(chilliController.processReply)
        .fail(function () {
                clearErrorMessages();
                display_error(Translator.trans("uam.js.login_failed_server_error"));
            }
        );
}

chilliController.tosGetResponse = function () {
    // Send Challenge to automac script which will give us the response to send
    // and the username (so we never know the password client side)

    // TODO get this from the router (grase_uam_toslogin)
    /* Build automac URL */
    const tosUrl = 'http://' + window.location.hostname + '/grase/uam/toslogin?challenge=' + encodeURIComponent(chilliController.challenge);


    chilliController.clientState = chilliController.stateCodes.AUTH_PENDING;

    $.ajax({
            url: tosUrl,
            dataType: "jsonp",
            timeout: 5000,
        })
        .done(chilliController.tosGetLogin)
        .fail(function () {
            clearErrorMessages();
            display_error(Translator.trans('uam.js.no_response_tos_server'));
        });
}

chilliController.tosGetLogin = function (resp) {
    // Check for an invalid response
    if (typeof (resp) == 'undefined' || typeof (resp.success) !== 'boolean') {
        display_error(Translator.trans("uam.js.tos_incorrect_response"));
        return false;
    }

    /*
     * Check if the response was success or failure. The check of username and response are a bit redundant, they'll
     * be missing if success is false, and they should be valid strings if success is true. It's still a good idea to
     * check them though
     */
    if (!resp.success || typeof (resp.username) !== 'string' || typeof (resp.response) !== 'string') {
        display_error(Translator.trans("uam.js.login_error"))
        return false
    }

    /* Build /logon command URL */
    const logonPath = 'logon?username=' + encodeURIComponent(resp.username) + '&response=' + encodeURIComponent(resp.response);

    chilliController.clientState = chilliController.stateCodes.AUTH_PENDING;

    ajaxChilliController(logonPath)
        .done(chilliController.processReply)
        .fail(function () {
                clearErrorMessages();
                display_error(Translator.trans("uam.js.tos_login_error"));
            }
        );
}

chilliController.processReply = function (resp) {
    // Clear any previous timeout we have running
    clearTimeout(chilliController.timeoutvar);

    //alert(resp);
    // Check for message (error)
    if (typeof (resp.message) == 'string') {
        error_message(Translator.trans(resp.message), 'alert-info');
    }

    if (typeof (resp.challenge) == 'string') {
        chilliController.challenge = resp.challenge;
    }

    //client state
    if ((resp.clientState === chilliController.stateCodes.NOT_AUTH) ||
        (resp.clientState === chilliController.stateCodes.AUTH) ||
        (resp.clientState === chilliController.stateCodes.AUTH_SPLASH) ||
        (resp.clientState === chilliController.stateCodes.AUTH_PENDING)) {

        if (resp.clientState === chilliController.stateCodes.NOT_AUTH) {

            pageStates.loginFormState()
            chilliController.clientState = chilliController.stateCodes.NOT_AUTH;

        }

        if (resp.clientState === chilliController.stateCodes.AUTH) {
            if (chilliController.clientState === chilliController.stateCodes.AUTH_PENDING) {
                // We have successfully logged in or changed states to logged in
                error_message(Translator.trans("uam.js.login_success"), 'alert-success');
                let userUrl = filterUserUrl(getQueryVariable('userurl'));
                if (typeof (userUrl) == 'string') {
                    userUrl = decodeURIComponent(userUrl);
                    error_message(Translator.trans('uam.js.login_continue_to_site', {userUrl: userUrl}), 'alert-success');
                }

            }
            chilliController.clientState = chilliController.stateCodes.AUTH;

            pageStates.loggedInFormState();
            //$('#loggedinusername').text('Logged in as ' + resp.session.userName);
            //$('#sessionstarttime').text(Translator.trans('uam.js.status.sessionStart', {startTime: chilliController.formatTime(resp.session.startTime)}));
            //$('#sessionTimeout').text('Session will end at ' + chilliController.formatTime(resp.session.sessionTimeout - resp.accounting.sessionTime));

            $.each(resp.session, function (index, value) {
                switch (index) {
                    case 'maxTotalOctets':
                        if(value === 0) { break; }
                        let remainingBytes = chilliController.formatBytes(value - resp.accounting.inputOctets - resp.accounting.outputOctets)
                        $('#sessionMaxTotalOctets').show();
                        $('#sessionMaxTotalOctets').html(Translator.trans('uam.js.status.remainingSessionData', { remainingSessionData: remainingBytes}))
                        // TODO Gigawords in resp.accounting
                        break;

                    case 'sessionTimeout':
                        if(value === 0) { break; }
                        let remainingTime = chilliController.formatTime(value - resp.accounting.sessionTime);
                        $('#sessionTimeout').show();
                        $('#sessionTimeout').html(Translator.trans('uam.js.status.remainingSessionTime', { remainingSessionTime: remainingTime}))
                        break;
                    case 'userName':
                        $('#loggedinuserName').show();
                        $('#loggedinuserName').html(Translator.trans('uam.js.status.loggedUsername', {username: value}))
                        break
                }
            });

            $.each(resp.redir, function (index, value) {
                switch (index) {
                    case 'logoutURL':
                        $('#logofflink').prop("href", value);
                        break;
                }
            });

            /*$.each(resp.accounting, function (index, value)
             {
             updateStatusPage(index, value);
             });*/

        }

        if (resp.clientState === chilliController.stateCodes.AUTH_PENDING) {
            chilliController.clientState = chilliController.stateCodes.AUTH_PENDING;
            pageStates.loadingFormState();
        }

    } else {
        display_error(Translator.trans("uam.js.error.unknown_clientstate_reply"));
    }

    // Clear any previous timeout we have running
    chilliController.timeoutvar = setTimeout(chilliController.updateStatus, 10000);
}

chilliController.updateStatus = function () {
    // Clear any previous timeout we have running
    clearTimeout(chilliController.timeoutvar);
    ajaxChilliController('status')
        .done(chilliController.processReply);
}

function ajaxChilliController(path) {
    return $.ajax(
        {
            url: chilliController.urlRoot + path,
            dataType: "jsonp",
            timeout: 5000
        });
}


chilliController.logoff = function () {
    ajaxChilliController('logoff')
        .done(chilliController.processReply)
        .fail(function() {
            display_error(Translator.trans("uam.js.error.failed_logoff"));
        });
}

chilliController.startLogin = function (event, type) {
    chilliController.loginType = type;
    pageStates.loadingFormState();
    clearErrorMessages();
    chilliController.challenge = null;
    chilliController.getChallenge();
    return false;
}

const pageStates = {
    loginFormState: function () {
        $('.alert-success').hide();
        $('#loginform').show();
        $('#tosaccept').show();
        $('#loading').hide();
        $('#loggedin').hide();
        $('#loggedin>span').hide();
        chilliController.loginType = "";
        console.log("Loading login form state");
    },
    loadingFormState: function () {
        $('#loginform').hide();
        $('#tosaccept').hide();
        $('#loading').show();
        $('#loggedin').hide();
        console.log("Loading loading form state");
    },
    loggedInFormState: function () {
        $('#loginform').hide();
        $('#tosaccept').hide();
        $('#loading').hide();
        $('#loggedin').show();
        // We don't want to save the password, even if it's a nice feature
        $("#password").val('');
        console.log("Loading logged in form state");
    }

}


function display_error(errormsg) {
    pageStates.loginFormState()
    error_message(errormsg, 'alert-danger');

}

function error_message(msg, type) {
    type = type || "";
    $("#errormessages").append(
        '<div class="alert alert-dismissible ' + type + ' fade show" role="alert">'
        + msg +
        '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>'
        + '</div>'
    );
}

function clearErrorMessages() {
    $("#errormessages").html('');
}

// Setup our forms and action links

$('#loginform_form').submit((event) => chilliController.startLogin(event, "USER"));

$('#tosaccept_form').submit((event) => chilliController.startLogin(event,"TOS"));


$('#logofflink').click(function () {
    confirm(Translator.trans("uam.js.prompt.confirm_logoff")) && chilliController.logoff();
    return false;
});

// Setup status window link

// TODO use the correct JS router for this
$('#statuslink').click(function () {
    const loginwindow = window.open('/grase/uam', 'grase_uam', 'width=300,height=400,status=yes,resizable=yes');
    if (loginwindow) {
        loginwindow.moveTo(100, 100);
        loginwindow.focus();
    }
});

if (window.name === 'grase_uam') {
    $('#statuslink').hide()
} else {
    $('#statuslink').show()
}

function filterUserUrl(userUrl)
{
    if (typeof (userUrl) == 'string') {
        let decodedUserUrl = decodeURIComponent(userUrl);
        if (decodedUserUrl === 'http://logout/' || decodedUserUrl === 'http://1.0.0.0/') {
            return null;
        }
    }
    return userUrl;
}

// Fire off our status updater
chilliController.updateStatus();

global.chilliController = chilliController; // TODO we can remove this now?
