(function (window, document, $, wp) {
    'use strict';

    var config = window.indigetalOffloadStreamPreviewNotice || {};
    var classes = config.classes || {};
    var dataAttributes = config.dataAttributes || {};
    var messages = config.messages || {};
    var overlayClass = classes.overlay || 'indigetal-offload-stream-preview-notice';
    var spinnerClass = classes.spinner || 'indigetal-offload-stream-preview-notice__spinner';
    var messageClass = classes.message || 'indigetal-offload-stream-preview-notice__message';
    var hostClass = overlayClass + '-host';
    var processingAttribute = dataAttributes.processing || 'data-indigetal-offload-stream-processing';
    var attachmentAttribute = dataAttributes.attachment || 'data-indigetal-offload-attachment-id';
    var stateAttribute = 'data-indigetal-offload-preview-state';
    var pollingIntervalMs = Math.max(1000, parseInt(config.pollingIntervalSeconds, 10) * 1000 || 5000);
    var pollingTimeoutMs = Math.max(pollingIntervalMs, parseInt(config.pollingTimeoutSeconds, 10) * 1000 || 600000);
    var scheduled = false;
    var pollTimer = null;
    var activePollKey = '';
    var activePollAttachmentId = '';
    var activePollState = null;
    var activePollStartedAt = 0;
    var terminalStates = {};
    var modelRefreshAttempts = {};
    var modelRefreshIntervalMs = Math.max(10000, pollingIntervalMs * 2);

    function getModelData(model) {
        if (!model) {
            return {};
        }

        if (typeof model.toJSON === 'function') {
            return model.toJSON() || {};
        }

        return model.attributes || {};
    }

    function getProcessingState(model) {
        var data = getModelData(model);
        var state = data.indigetalOffloadStreamProcessing || data.indigetal_offload_stream_processing;
        var videoId;

        if (state && state.attachmentId && (state.videoId || state.offloading)) {
            return state;
        }

        if (!hasVisibleMediaElementError() || !isBunnyStreamPreviewData(data)) {
            return null;
        }

        videoId = getStreamVideoIdFromUrl(data.url);
        if (!videoId || !data.id) {
            return null;
        }

        return {
            processing: true,
            previewPreparing: true,
            hasThumbnail: !!(data.thumb || data.image),
            previewReadyStatus: 3,
            attachmentId: data.id,
            videoId: videoId,
            statusRoute: config.statusRoute || '/indigetal-offload/v1/stream/video-status',
            message: messages.processing || '',
        };
    }

    function isBunnyStreamPreviewData(data) {
        var url = data && typeof data.url === 'string' ? data.url : '';

        return data && 'video' === data.type && url.indexOf('/play_') !== -1 && url.indexOf('.mp4') !== -1;
    }

    function getStreamVideoIdFromUrl(url) {
        var match;

        if (typeof url !== 'string') {
            return '';
        }

        match = url.match(/\/([a-f0-9-]{32,36})\/play_[^/?#]+\.mp4/i);

        return match && match[1] ? match[1] : '';
    }

    function getAttachmentId(model) {
        var data = getModelData(model);

        return data && data.id ? String(data.id) : '';
    }

    function hasVisibleMediaElementError() {
        return $('.mejs-overlay-error:visible, .mejs-cannotplay:visible, .mejs__overlay-error:visible, .mejs__cannotplay:visible').length > 0;
    }

    function getActiveAttachmentModel() {
        var frame = wp && wp.media ? wp.media.frame : null;
        var state;
        var selection;
        var attachment;

        if (frame && typeof frame.state === 'function') {
            state = frame.state();
        }

        if (state && typeof state.get === 'function') {
            selection = state.get('selection');

            if (selection && typeof selection.first === 'function') {
                attachment = selection.first();
                if (attachment) {
                    return attachment;
                }
            }

            attachment = state.get('attachment');
            if (attachment) {
                return attachment;
            }

            attachment = state.get('model');
            if (attachment) {
                return attachment;
            }
        }

        return null;
    }

    function getPreviewContainers() {
        var $containers = $(
            [
                '.media-modal .attachment-media-view',
                '.media-modal .attachment-details .thumbnail',
                '.media-frame-content .attachment-media-view',
                '.media-frame-content .attachment-details .thumbnail',
                '.upload-php .attachment-details .attachment-media-view',
                '.upload-php .attachment-details .thumbnail',
            ].join(',')
        ).filter(':visible');

        return $containers.filter(function () {
            var container = this;

            return !$containers.toArray().some(function (candidate) {
                return candidate !== container && $.contains(container, candidate);
            });
        });
    }

    function removeOverlays() {
        $('.' + overlayClass).remove();
        $('.' + hostClass)
            .removeClass(hostClass)
            .removeAttr(processingAttribute)
            .removeAttr(attachmentAttribute)
            .removeAttr(stateAttribute);
    }

    function refreshModelForProcessingState(model) {
        var data = getModelData(model);
        var attachmentId = getAttachmentId(model);
        var now = Date.now();

        if (!attachmentId || !model || typeof model.fetch !== 'function') {
            return false;
        }

        if (!hasVisibleMediaElementError()) {
            return false;
        }

        if (modelRefreshAttempts[attachmentId] && now - modelRefreshAttempts[attachmentId] < modelRefreshIntervalMs) {
            return true;
        }

        modelRefreshAttempts[attachmentId] = now;
        model.fetch({
            success: scheduleSync,
            error: scheduleSync,
        });

        return true;
    }

    function buildOverlay(state, mode) {
        var message = getOverlayMessage(state, mode);
        var $overlay = $('<div />', {
            class: overlayClass + ' ' + overlayClass + '--' + mode,
            role: 'status',
            'aria-live': 'polite',
        });

        $('<span />', {
            class: spinnerClass,
            'aria-hidden': 'true',
        }).appendTo($overlay);

        $('<p />', {
            class: messageClass,
            text: message,
        }).appendTo($overlay);

        return $overlay;
    }

    function getOverlayMessage(state, mode) {
        if ('error' === mode) {
            return messages.error || state.message || '';
        }

        if ('timeout' === mode) {
            return messages.timeout || state.message || '';
        }

        if ('failed' === mode) {
            return messages.error || state.message || '';
        }

        if ('ready' === mode) {
            return messages.ready || state.message || '';
        }

        return state.message || messages.processing || '';
    }

    function getStateMode(state) {
        var attachmentId = String(state.attachmentId || '');

        if (terminalStates[attachmentId]) {
            return terminalStates[attachmentId];
        }

        return 'processing';
    }

    function overlayMatches(state, mode) {
        var $containers = getPreviewContainers();
        var attachmentId = String(state.attachmentId || '');
        var $hosts;

        if (!$containers.length) {
            return false;
        }

        $hosts = $containers.filter('.' + hostClass);

        return $hosts.length === $containers.length &&
            $('.' + overlayClass).length === $containers.length &&
            $hosts.filter('[' + attachmentAttribute + '="' + attachmentId + '"][' + stateAttribute + '="' + mode + '"]').length === $containers.length;
    }

    function renderOverlay(state) {
        var $containers = getPreviewContainers();
        var mode = getStateMode(state);

        if (overlayMatches(state, mode)) {
            return;
        }

        removeOverlays();

        if (!$containers.length) {
            return;
        }

        $containers.each(function () {
            var $container = $(this);

            $container
                .addClass(hostClass)
                .attr(processingAttribute, 'true')
                .attr(attachmentAttribute, String(state.attachmentId || ''))
                .attr(stateAttribute, mode);

            $container.append(buildOverlay(state, mode));
        });
    }

    function getPollKey(state) {
        return String(state.attachmentId || '') + ':' + String(state.videoId || 'offloading');
    }

    function stopPolling() {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
        }

        pollTimer = null;
        activePollKey = '';
        activePollAttachmentId = '';
        activePollState = null;
        activePollStartedAt = 0;
    }

    function getCurrentStateForPolling() {
        var model = getActiveAttachmentModel();
        var state = getProcessingState(model);

        if (!state || terminalStates[String(state.attachmentId || '')]) {
            return null;
        }

        return state;
    }

    function schedulePoll(delay) {
        if (!activePollState) {
            return;
        }

        if (pollTimer) {
            window.clearTimeout(pollTimer);
        }

        pollTimer = window.setTimeout(pollActivePreview, delay);
    }

    function buildReadyStateFromModel(model, fallbackState) {
        var data = getModelData(model);

        if (!data || !data.id || !isBunnyStreamPreviewData(data)) {
            return null;
        }

        return {
            processing: false,
            previewPreparing: false,
            attachmentId: data.id,
            videoId: getStreamVideoIdFromUrl(data.url) || (fallbackState && fallbackState.videoId) || '',
            message: messages.ready || '',
        };
    }

    function refreshOffloadingAttachment(state) {
        var model;

        if (!wp || !wp.media || !state.attachmentId) {
            handlePollFailure(state);
            return;
        }

        model = wp.media.attachment(state.attachmentId);
        if (!model || typeof model.fetch !== 'function') {
            handlePollFailure(state);
            return;
        }

        model.fetch({
            success: function () {
                var nextState = getProcessingState(model);
                var readyState;

                if (nextState && nextState.videoId) {
                    stopPolling();
                    scheduleSync();
                    return;
                }

                readyState = buildReadyStateFromModel(model, state);
                if (readyState) {
                    terminalStates[String(state.attachmentId || '')] = 'ready';
                    stopPolling();
                    renderOverlay(readyState);
                    return;
                }

                schedulePoll(pollingIntervalMs);
            },
            error: function () {
                handlePollFailure(state);
            },
        });
    }

    function handlePollResult(state, result) {
        var attachmentId = String(state.attachmentId || '');
        var status = parseInt(result && result.status, 10);
        var encodeProgress = parseInt(result && result.encodeProgress, 10);

        if (3 === status || (4 === status && encodeProgress >= 100)) {
            terminalStates[attachmentId] = 'ready';
            stopPolling();
            renderOverlay(state);
            return;
        }

        if (5 === status) {
            terminalStates[attachmentId] = 'failed';
            stopPolling();
            renderOverlay(state);
            return;
        }

        schedulePoll(pollingIntervalMs);
    }

    function handlePollFailure(state) {
        terminalStates[String(state.attachmentId || '')] = 'error';
        stopPolling();
        renderOverlay(state);
    }

    function pollActivePreview() {
        var state = getCurrentStateForPolling();
        var path;

        pollTimer = null;

        if (!state || getPollKey(state) !== activePollKey) {
            stopPolling();
            return;
        }

        if (Date.now() - activePollStartedAt >= pollingTimeoutMs) {
            terminalStates[String(state.attachmentId || '')] = 'timeout';
            stopPolling();
            renderOverlay(state);
            return;
        }

        if (!wp || !wp.apiFetch) {
            handlePollFailure(state);
            return;
        }

        if (!state.videoId) {
            refreshOffloadingAttachment(state);
            return;
        }

        path = String(config.statusRoute || '/indigetal-offload/v1/stream/video-status') +
            '?video_id=' + encodeURIComponent(state.videoId) +
            '&attachment_id=' + encodeURIComponent(state.attachmentId);

        wp.apiFetch({
            path: path,
            headers: {
                'X-WP-Nonce': config.nonce || '',
            },
        }).then(function (result) {
            handlePollResult(state, result);
        }).catch(function () {
            handlePollFailure(state);
        });
    }

    function ensurePolling(state) {
        var key = getPollKey(state);
        var attachmentId = String(state.attachmentId || '');
        var previousAttachmentId = activePollAttachmentId;
        var previousStartedAt = activePollStartedAt;

        if (terminalStates[attachmentId]) {
            stopPolling();
            return;
        }

        if (activePollKey === key) {
            return;
        }

        stopPolling();
        activePollKey = key;
        activePollAttachmentId = attachmentId;
        activePollState = state;
        activePollStartedAt = previousAttachmentId === attachmentId && previousStartedAt > 0 ? previousStartedAt : Date.now();
        schedulePoll(0);
    }

    function syncOverlay() {
        var model = getActiveAttachmentModel();
        var state = getProcessingState(model);
        var attachmentId = state ? String(state.attachmentId || '') : '';

        scheduled = false;

        if (!state) {
            if (refreshModelForProcessingState(model)) {
                return;
            }

            removeOverlays();
            stopPolling();
            return;
        }

        renderOverlay(state);
        ensurePolling(state);
    }

    function scheduleSync() {
        if (scheduled) {
            return;
        }

        scheduled = true;
        window.setTimeout(syncOverlay, 50);
    }

    function bindMediaEvents() {
        $(document).on(
            'click keyup',
            '.media-modal, .media-frame, .attachments-browser, .attachment',
            scheduleSync
        );

        document.addEventListener('error', function (event) {
            if (event.target && 'VIDEO' === event.target.tagName) {
                scheduleSync();
            }
        }, true);

        if (wp && wp.media && wp.media.events && typeof wp.media.events.on === 'function') {
            wp.media.events.on('selection:toggle selection:single attachment:details:shift', scheduleSync);
        }
    }

    $(function () {
        if (!wp || !wp.media) {
            return;
        }

        bindMediaEvents();
        scheduleSync();
    });
})(window, document, window.jQuery, window.wp);
