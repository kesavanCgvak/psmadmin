(function () {
    'use strict';

    var config = window.PSM_CHATBOT || {};
    var form = document.getElementById('chatbot-form');
    var input = document.getElementById('chatbot-input');
    var messages = document.getElementById('chatbot-messages');
    var errorBox = document.getElementById('chatbot-error');
    var sendBtn = document.getElementById('chatbot-send');
    var newChatBtn = document.getElementById('chatbot-new-chat');
    var conversationId = null;
    var sending = false;

    if (!form || !input || !messages || !config.sendUrl) {
        return;
    }

    function showError(message) {
        if (!errorBox) {
            return;
        }
        errorBox.textContent = message || 'Something went wrong.';
        errorBox.classList.remove('d-none');
    }

    function clearError() {
        if (!errorBox) {
            return;
        }
        errorBox.textContent = '';
        errorBox.classList.add('d-none');
    }

    function appendBubble(role, content) {
        var bubble = document.createElement('div');
        bubble.className = 'chatbot-bubble chatbot-bubble-' + role;

        var body = document.createElement('div');
        body.className = 'chatbot-bubble-content';
        body.textContent = content;

        bubble.appendChild(body);
        messages.appendChild(bubble);
        messages.scrollTop = messages.scrollHeight;

        return bubble;
    }

    function setSending(isSending) {
        sending = isSending;
        if (sendBtn) {
            sendBtn.disabled = isSending;
        }
        input.disabled = isSending;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (sending) {
            return;
        }

        var text = (input.value || '').trim();
        if (!text) {
            return;
        }

        clearError();
        appendBubble('user', text);
        input.value = '';
        setSending(true);

        var typing = appendBubble('assistant', 'Thinking…');
        typing.classList.add('chatbot-typing');

        fetch(config.sendUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken || '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                message: text,
                conversation_id: conversationId
            })
        })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            })
            .then(function (result) {
                typing.remove();

                if (!result.ok || !result.payload || !result.payload.success) {
                    var message = (result.payload && result.payload.message)
                        ? result.payload.message
                        : 'Unable to get a reply.';
                    showError(message);
                    appendBubble('assistant', 'Sorry, I could not process that right now.');
                    return;
                }

                conversationId = result.payload.data.conversation_id;
                appendBubble('assistant', result.payload.data.assistant_message.content);
            })
            .catch(function () {
                typing.remove();
                showError('Network error while contacting the chatbot.');
                appendBubble('assistant', 'Sorry, I could not process that right now.');
            })
            .finally(function () {
                setSending(false);
                input.focus();
            });
    });

    if (newChatBtn) {
        newChatBtn.addEventListener('click', function () {
            conversationId = null;
            clearError();
            messages.innerHTML = '';
            appendBubble('assistant', 'Started a new chat. Ask me anything about PSM.');
            input.focus();
        });
    }

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });
})();
