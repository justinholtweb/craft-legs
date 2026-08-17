/**
 * Legs — the CKEditor plugin.
 *
 * What it stores is a plain block:
 *
 *     <div class="legs-embed" data-legs-handle="prices">{legs:prices:render}</div>
 *
 * That is the whole integration. The ref tag inside is what actually renders the table, and
 * Craft parses ref tags over every rich-text value on its own — so this file only helps an
 * author write that tag, and shows something better than raw braces while they edit. If the
 * plugin is missing, or the content is moved to a Redactor field, or the editor is swapped out
 * entirely, the embed still renders.
 */

import { ButtonView, Command, Plugin, Widget, toWidget } from 'ckeditor5';

const ICON = `<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
    <path d="M2 3h16v3H2V3zm0 5h7v3H2V8zm9 0h7v3h-7V8zM2 13h7v3H2v-3zm9 0h7v3h-7v-3z"/>
</svg>`;

class InsertLegsTableCommand extends Command {
    refresh() {
        const model = this.editor.model;

        this.isEnabled = model.schema.findAllowedParent(
            model.document.selection.getFirstPosition(),
            'legsEmbed',
        ) !== null;
    }

    execute(options) {
        const editor = this.editor;

        editor.model.change((writer) => {
            const embed = writer.createElement('legsEmbed', {
                handle: options.handle,
                label: options.label || options.handle,
                options: options.options || '',
            });

            editor.model.insertObject(embed, null, null, { setSelection: 'after' });
        });
    }
}

class LegsTableEditing extends Plugin {
    static get requires() {
        return [Widget];
    }

    static get pluginName() {
        return 'LegsTableEditing';
    }

    init() {
        const editor = this.editor;

        editor.model.schema.register('legsEmbed', {
            inheritAllFrom: '$blockObject',
            allowAttributes: ['handle', 'label', 'options'],
        });

        editor.conversion.for('upcast').elementToElement({
            view: {
                name: 'div',
                classes: 'legs-embed',
            },
            model: (viewElement, { writer }) => writer.createElement('legsEmbed', {
                handle: viewElement.getAttribute('data-legs-handle') || '',
                label: viewElement.getAttribute('data-legs-label')
                    || viewElement.getAttribute('data-legs-handle')
                    || '',
                options: viewElement.getAttribute('data-legs-options') || '',
            }),
        });

        editor.conversion.for('dataDowncast').elementToElement({
            model: 'legsEmbed',
            view: (modelElement, { writer }) => {
                const handle = modelElement.getAttribute('handle') || '';
                const options = modelElement.getAttribute('options') || '';
                const attributes = { class: 'legs-embed', 'data-legs-handle': handle };

                if (options) {
                    attributes['data-legs-options'] = options;
                }

                // A raw element, so the ref tag is written as plain text rather than as model
                // content CKEditor would then try to own.
                return writer.createRawElement('div', attributes, (domElement) => {
                    domElement.textContent = options
                        ? `{legs:${handle}:render(${options})}`
                        : `{legs:${handle}:render}`;
                });
            },
        });

        editor.conversion.for('editingDowncast').elementToElement({
            model: 'legsEmbed',
            view: (modelElement, { writer }) => {
                const handle = modelElement.getAttribute('handle') || '';
                const label = modelElement.getAttribute('label') || handle;
                const options = modelElement.getAttribute('options') || '';

                const container = writer.createContainerElement('div', { class: 'legs-embed' }, [
                    writer.createRawElement('div', { class: 'legs-embed-card' }, (domElement) => {
                        domElement.textContent = options
                            ? `${label} (${handle} · ${options})`
                            : `${label} (${handle})`;
                    }),
                ]);

                return toWidget(container, writer, { label: `Legs table: ${label}` });
            },
        });

        editor.commands.add('insertLegsTable', new InsertLegsTableCommand(editor));
    }
}

export class LegsTable extends Plugin {
    static get requires() {
        return [LegsTableEditing];
    }

    static get pluginName() {
        return 'LegsTable';
    }

    init() {
        const editor = this.editor;

        editor.ui.componentFactory.add('legsTable', (locale) => {
            const button = new ButtonView(locale);
            const command = editor.commands.get('insertLegsTable');

            button.set({
                label: Craft.t('legs', 'Table'),
                icon: ICON,
                tooltip: true,
            });

            button.bind('isEnabled').to(command, 'isEnabled');

            button.on('execute', () => {
                window.LegsPicker.open((table, overrides) => {
                    editor.execute('insertLegsTable', {
                        handle: table.handle,
                        label: table.title,
                        options: window.LegsPicker.toEmbedOptions(overrides || {}),
                    });
                    editor.editing.view.focus();
                });
            });

            return button;
        });
    }
}

export default LegsTable;
