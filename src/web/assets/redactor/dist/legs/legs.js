/**
 * Legs — the Redactor plugin.
 *
 * Inserts exactly the same block the CKEditor plugin does:
 *
 *     <div class="legs-embed" data-legs-handle="prices">{legs:prices:render}</div>
 *
 * so content can move between a Redactor field and a CKEditor field without anything to
 * migrate, and either way it is Craft's own ref-tag parsing that renders the table.
 */
(function($R) {
    'use strict';

    $R.add('plugin', 'legs', {
        translations: {
            en: {
                legs: {
                    title: 'Insert a table',
                },
            },
        },

        init: function(app) {
            this.app = app;
            this.toolbar = app.toolbar;
            this.insertion = app.insertion;
            this.lang = app.lang;
        },

        start: function() {
            var button = this.toolbar.addButton('legs', {
                title: this.lang.get('legs.title'),
                api: 'plugin.legs.open',
            });

            button.setIcon('<i class="re-icon-table"></i>');
        },

        open: function() {
            var insertion = this.insertion;

            window.LegsPicker.open(function(table, overrides) {
                insertion.insertHtml(window.LegsPicker.embedHtml(table, overrides));
            });
        },
    });
})(Redactor);
