/* Impact Websites Core — Meta Box JS */
(function($) {
    'use strict';

    // Tab switching
    function initTabs(wrap) {
        var tabs    = wrap.querySelectorAll('.cdc-tab');
        var panels  = wrap.querySelectorAll('.cdc-panel');

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var target = tab.dataset.target;
                tabs.forEach(function(t) { t.classList.remove('cdc-tab--active'); });
                panels.forEach(function(p) { p.classList.remove('cdc-panel--active'); });
                tab.classList.add('cdc-tab--active');
                var panel = document.getElementById(target);
                if (panel) {
                    panel.classList.add('cdc-panel--active');
                    // Refresh CodeMirror in newly visible panel
                    var cm = panel.querySelector('.CodeMirror');
                    if (cm && cm.CodeMirror) cm.CodeMirror.refresh();
                }
            });
        });
    }

    // CodeMirror init
    function initEditors() {
        if (typeof wp === 'undefined' || !wp.codeEditor) return;

        var settings = (typeof cdcMetaBox !== 'undefined' && cdcMetaBox.cmSettings)
            ? cdcMetaBox.cmSettings
            : {};

        settings = $.extend(true, {}, settings, {
            codemirror: {
                mode: 'htmlmixed',
                lineNumbers: true,
                lineWrapping: true,
                indentUnit: 4,
                indentWithTabs: false,
                extraKeys: { 'Ctrl-Space': 'autocomplete' }
            }
        });

        document.querySelectorAll('.cdc-code-editor').forEach(function(textarea) {
            wp.codeEditor.initialize(textarea, settings);
        });
    }

    // Character counters for SEO fields
    function initCharCounters() {
        document.querySelectorAll('.cdc-char-count').forEach(function(counter) {
            var fieldId = counter.dataset.field;
            var max     = parseInt(counter.dataset.max, 10);
            var field   = document.getElementById(fieldId);
            var countEl = counter.querySelector('.cdc-count');

            if (!field || !countEl) return;

            function update() {
                var len = field.value.length;
                countEl.textContent = len;
                counter.classList.toggle('cdc-over', len > max);
            }

            field.addEventListener('input', update);
            update();
        });
    }

    $(document).ready(function() {
        var metabox = document.querySelector('.cdc-metabox');
        if (metabox) initTabs(metabox);

        var globalsWrap = document.querySelector('.cdc-globals-wrap');
        if (globalsWrap) initTabs(globalsWrap);

        initEditors();
        initCharCounters();
    });

}(jQuery));
