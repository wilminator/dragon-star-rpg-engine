// Security keys and passkeys (WebAuthn) in the browser.
//
// A button carrying data-webauthn="get" (use a key) or "create"
// (register one), and the WebAuthn options from the server as JSON in
// data-options (binary values base64url-encoded), runs the browser's
// dialog when clicked, puts the answer in its form's hidden
// webauthn_* fields (base64url), and submits the form.
(function () {
    'use strict';

    function fromBase64url(text) {
        var base64 = text.replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4) base64 += '=';
        var binary = atob(base64);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes.buffer;
    }

    function toBase64url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function decodeOptions(options) {
        var key = options.publicKey;
        key.challenge = fromBase64url(key.challenge);
        if (key.user) key.user.id = fromBase64url(key.user.id);
        (key.excludeCredentials || []).forEach(function (c) { c.id = fromBase64url(c.id); });
        (key.allowCredentials || []).forEach(function (c) { c.id = fromBase64url(c.id); });
        return options;
    }

    function setField(form, name, value) {
        var field = form.querySelector('input[name="' + name + '"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = name;
            form.appendChild(field);
        }
        field.value = value;
    }

    function showProblem(button, text) {
        var note = button.parentNode.querySelector('.webauthn-problem');
        if (!note) {
            note = document.createElement('span');
            note.className = 'webauthn-problem problem';
            button.parentNode.appendChild(note);
        }
        note.textContent = ' ' + text;
    }

    function run(button) {
        if (!window.PublicKeyCredential) {
            showProblem(button, "This browser can't use security keys.");
            return;
        }
        var form = button.form;
        var mode = button.getAttribute('data-webauthn');
        var options = decodeOptions(JSON.parse(button.getAttribute('data-options')));
        var ask = mode === 'create' ? navigator.credentials.create(options) : navigator.credentials.get(options);
        ask.then(function (credential) {
            var response = credential.response;
            setField(form, 'webauthn_client_data', toBase64url(response.clientDataJSON));
            if (mode === 'create') {
                setField(form, 'webauthn_attestation', toBase64url(response.attestationObject));
            } else {
                setField(form, 'webauthn_id', toBase64url(credential.rawId));
                setField(form, 'webauthn_authenticator_data', toBase64url(response.authenticatorData));
                setField(form, 'webauthn_signature', toBase64url(response.signature));
            }
            // A code field may be required for typing; the key answers instead.
            Array.prototype.forEach.call(form.querySelectorAll('input[name="code"]'), function (f) { f.required = false; });
            form.submit();
        }).catch(function (error) {
            showProblem(button, error && error.name === 'NotAllowedError'
                ? 'Cancelled, or the key took too long.' : "The security key didn't work: " + (error && error.message));
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('button[data-webauthn]');
        if (button) {
            event.preventDefault();
            run(button);
        }
    });
})();
