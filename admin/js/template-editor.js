(function($) {
    'use strict';

    $(document).ready(function() {
        initAceEditor();
    });

    function initAceEditor() {
        // Initialize editors
        var successEditor = ace.edit('success-template-editor');
        var failedEditor = ace.edit('failed-template-editor');

        // Configure each editor
        [successEditor, failedEditor].forEach(function(editor) {
            editor.setTheme('ace/theme/monokai');
            editor.getSession().setMode('ace/mode/html');
            editor.setOptions({
                fontSize: '14px',
                showPrintMargin: false,
                showGutter: true,
                highlightActiveLine: true,
                enableBasicAutocompletion: true,
                enableSnippets: true,
                enableLiveAutocompletion: true,
                useSoftTabs: true,
                tabSize: 4,
                wrap: true
            });

            // Add format command
            editor.commands.addCommand({
                name: 'format',
                bindKey: {win: 'Ctrl-B', mac: 'Command-B'},
                exec: function(editor) {
                    var beautified = html_beautify(editor.getValue(), {
                        indent_size: 4,
                        wrap_line_length: 80,
                        preserve_newlines: true,
                        jslint_happy: false,
                        end_with_newline: false,
                        indent_inner_html: true
                    });
                    editor.setValue(beautified, -1);
                }
            });
        });

        // Add format buttons
        addFormatButton('success-template-editor', successEditor);
        addFormatButton('failed-template-editor', failedEditor);

        // Update hidden textareas before form submission
        $('form').on('submit', function() {
            $('textarea[name="customer_success_email_template"]').val(successEditor.getValue());
            $('textarea[name="customer_failed_email_template"]').val(failedEditor.getValue());
        });
    }

    function addFormatButton(editorId, editor) {
        var button = $('<button type="button" class="button format-html">Format HTML</button>');
        $('#' + editorId).before(button);

        button.on('click', function(e) {
            e.preventDefault();
            editor.execCommand('format');
        });
    }
})(jQuery);
