window.addEventListener('DOMContentLoaded', function () {
	const buttons = document.querySelectorAll('.service-cpt-media-button');
	const removeButtons = document.querySelectorAll('.service-cpt-media-remove');
	const sections = document.querySelectorAll('.service-cpt-section[data-autotoggle="1"]');
	const tableBuilders = document.querySelectorAll('.service-cpt-table-builder');
	const canUseMedia = Boolean(buttons.length && window.wp && wp.media);
	const postForm = document.getElementById('post');
	const publishButton = document.getElementById('publish');
	const saveButton = document.getElementById('save-post');
	let ctaNotice = null;

	const hasText = (value) => {
		const text = String(value || '').trim();
		return text !== '' && text !== '0';
	};

	const getField = (name) => document.querySelector(`[name="${name}"]`);
	const stripHtml = (value) => String(value || '').replace(/<[^>]*>/g, '');
	const getFieldValue = (name) => {
		const field = getField(name);
		if (!field) {
			return '';
		}
		if (window.tinymce && field.id) {
			const editor = tinymce.get(field.id);
			if (editor && !editor.isHidden()) {
				return editor.getContent({ format: 'text' });
			}
		}
		return field.value || '';
	};
	const hasCompleteLink = (labelName, urlName) => hasText(getFieldValue(labelName)) && hasText(getFieldValue(urlName));
	const openSectionForField = (name) => {
		const field = getField(name);
		const section = field ? field.closest('details.service-cpt-section') : null;
		if (section) {
			section.open = true;
		}
	};
	const getMissingCtaSections = () => {
		const missing = [];
		if (
			getField('sp_hero_primary_label') &&
			!hasCompleteLink('sp_hero_primary_label', 'sp_hero_primary_url') &&
			!hasCompleteLink('sp_hero_secondary_label', 'sp_hero_secondary_url')
		) {
			missing.push({ label: 'Hero CTA', field: 'sp_hero_primary_label' });
		}
		if (
			getField('sp_sidebar_title') &&
			!hasText(getFieldValue('sp_sidebar_image')) &&
			!hasText(getFieldValue('sp_sidebar_title')) &&
			!hasText(stripHtml(getFieldValue('sp_sidebar_copy'))) &&
			!hasCompleteLink('sp_sidebar_primary_label', 'sp_sidebar_primary_url') &&
			!hasCompleteLink('sp_sidebar_secondary_label', 'sp_sidebar_secondary_url')
		) {
			missing.push({ label: 'Sidebar CTA', field: 'sp_sidebar_title' });
		}
		if (
			getField('sp_cta_title') &&
			!hasText(getFieldValue('sp_cta_title')) &&
			!hasText(getFieldValue('sp_cta_bullet_1')) &&
			!hasText(getFieldValue('sp_cta_bullet_2')) &&
			!hasText(getFieldValue('sp_cta_bullet_3')) &&
			!hasCompleteLink('sp_cta_button_label', 'sp_cta_button_url') &&
			!hasCompleteLink('sp_cta_more_text', 'sp_cta_more_url')
		) {
			missing.push({ label: 'Wide CTA', field: 'sp_cta_title' });
		}
		return missing;
	};
	const getCtaMessage = (missing) => `Fill in the CTA fields before saving this service page. Missing: ${missing.map((item) => item.label).join(', ')}. You can also configure global CTAs in Settings > Service Pages.`;
	const showCtaNotice = (message) => {
		if (!ctaNotice) {
			ctaNotice = document.createElement('div');
			ctaNotice.className = 'notice notice-error service-cpt-cta-validation-notice';
			const titleWrap = document.querySelector('.wrap h1, .wrap h2, #wpbody-content .wrap');
			const parent = document.querySelector('.wrap') || document.getElementById('wpbody-content');
			if (parent) {
				if (titleWrap && titleWrap.parentNode === parent) {
					parent.insertBefore(ctaNotice, titleWrap.nextSibling);
				} else {
					parent.insertBefore(ctaNotice, parent.firstChild);
				}
			}
		}
		ctaNotice.innerHTML = `<p>${message}</p>`;
		ctaNotice.style.display = '';
	};
	const hideCtaNotice = () => {
		if (ctaNotice) {
			ctaNotice.style.display = 'none';
		}
	};
	const updateCtaValidation = () => {
		const missing = getMissingCtaSections();
		const isMissing = missing.length > 0;

		if (publishButton) {
			publishButton.disabled = isMissing;
			publishButton.classList.toggle('disabled', isMissing);
			publishButton.setAttribute('aria-disabled', isMissing ? 'true' : 'false');
		}

		if (saveButton) {
			saveButton.disabled = isMissing;
			saveButton.classList.toggle('disabled', isMissing);
			saveButton.setAttribute('aria-disabled', isMissing ? 'true' : 'false');
		}

		if (!isMissing) {
			hideCtaNotice();
			return true;
		}

		missing.forEach((item) => openSectionForField(item.field));
		showCtaNotice(getCtaMessage(missing));
		return false;
	};

	if (postForm) {
		postForm.addEventListener('submit', (event) => {
			if (!updateCtaValidation()) {
				event.preventDefault();
				event.stopPropagation();
				const notice = document.querySelector('.service-cpt-cta-validation-notice');
				if (notice) {
					notice.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			}
		});
		postForm.addEventListener('input', updateCtaValidation);
		postForm.addEventListener('change', updateCtaValidation);
		window.setTimeout(updateCtaValidation, 250);
	}

	const updateSelectButtonLabel = (button, hasValue) => {
		if (!button) {
			return;
		}
		const selectLabel = button.getAttribute('data-select-label') || 'Select image';
		const changeLabel = button.getAttribute('data-change-label') || 'Change image';
		button.textContent = hasValue ? changeLabel : selectLabel;
	};

	const updatePreview = (previewSelector, url) => {
		if (!previewSelector) {
			return;
		}
		const container = document.querySelector(previewSelector);
		if (!container) {
			return;
		}
		const img = container.querySelector('img');
		const placeholder = container.querySelector('.service-cpt-image-placeholder');
		const hasImage = Boolean(url);

		if (img) {
			if (hasImage) {
				img.src = url;
			} else {
				img.removeAttribute('src');
			}
		}

		container.classList.toggle('has-image', hasImage);
		container.classList.toggle('is-empty', !hasImage);
		if (placeholder) {
			placeholder.style.display = hasImage ? 'none' : 'block';
		}
	};

	if (canUseMedia) {
		buttons.forEach((button) => {
		const target = button.getAttribute('data-target');
		const preview = button.getAttribute('data-preview');

		if (!target) {
			return;
		}

		button.addEventListener('click', function (event) {
			event.preventDefault();

			const frame = wp.media({
				title: button.getAttribute('data-title') || 'Select image',
				button: { text: button.getAttribute('data-button') || 'Use image' },
				multiple: false,
				library: { type: 'image' },
			});

			frame.on('select', function () {
				const attachment = frame.state().get('selection').first().toJSON();
				const input = document.querySelector(`input[name=\"${target}\"]`);
				const removeButton = document.querySelector(`.service-cpt-media-remove[data-target=\"${target}\"]`);

				if (input) {
					input.value = attachment.id;
				}

				if (preview) {
					updatePreview(preview, attachment.url || '');
				}

				updateSelectButtonLabel(button, Boolean(attachment.id));
				if (removeButton) {
					removeButton.disabled = !attachment.id;
				}
			});

			frame.open();
		});
		});
	}

	removeButtons.forEach((removeButton) => {
		const target = removeButton.getAttribute('data-target');
		const preview = removeButton.getAttribute('data-preview');
		const buttonSelector = removeButton.getAttribute('data-button');
		const selectButton = buttonSelector ? document.querySelector(buttonSelector) : null;

		if (!target) {
			return;
		}

		removeButton.addEventListener('click', function (event) {
			event.preventDefault();
		const input = document.querySelector(`input[name=\"${target}\"]`);
		if (input) {
			input.value = '';
		}

		if (preview) {
			updatePreview(preview, '');
		}

		updateSelectButtonLabel(selectButton, false);
		removeButton.disabled = true;
	});
	});

	sections.forEach((section) => {
		const fields = section.querySelectorAll('.service-cpt-section-field');
		if (!fields.length) {
			return;
		}
		const updateState = () => {
			let hasValue = false;
			fields.forEach((field) => {
				if (field.disabled) {
					return;
				}
				if (field.type === 'checkbox' || field.type === 'radio') {
					if (field.checked) {
						hasValue = true;
					}
					return;
				}
				if (field.value && field.value.trim() !== '') {
					hasValue = true;
				}
			});
			if (hasValue) {
				section.open = true;
			}
		};
		updateState();
		fields.forEach((field) => {
			field.addEventListener('input', updateState);
		});
	});

	tableBuilders.forEach((builder) => {
		const target = builder.getAttribute('data-target');
		if (!target) {
			return;
		}
		const textarea = document.querySelector(`textarea[name=\"${target}\"]`);
		const rowsContainer = builder.querySelector('.service-cpt-table-rows');
		const addRowButton = builder.querySelector('.service-cpt-table-add-row');
		const addColButton = builder.querySelector('.service-cpt-table-add-col');
		const removeColButton = builder.querySelector('.service-cpt-table-remove-col');
		const minColumns = parseInt(builder.getAttribute('data-min-columns') || '2', 10) || 2;

		if (!textarea || !rowsContainer || !addRowButton || !addColButton || !removeColButton) {
			return;
		}

		const parseLines = (value) => {
			return value
				.split(/\r?\n/)
				.map((line) => line.trim())
				.filter((line) => line !== '')
				.map((line) => line.split('|').map((cell) => cell.trim()));
		};

		const normalizeRows = (rows, columnCount) => {
			return rows.map((row) => {
				const next = Array.isArray(row) ? row.slice(0, columnCount) : [];
				while (next.length < columnCount) {
					next.push('');
				}
				return next;
			});
		};

		const buildCell = (value) => {
			const input = document.createElement('input');
			input.type = 'text';
			input.className = 'regular-text service-cpt-section-field service-cpt-table-cell';
			input.value = value || '';
			return input;
		};

		const syncTextarea = () => {
			const rows = Array.from(rowsContainer.querySelectorAll('.service-cpt-table-row')).map((row) => {
				const cells = Array.from(row.querySelectorAll('input')).map((input) => input.value.trim());
				return cells;
			});
			const filtered = rows.filter((row) => row.some((cell) => cell !== ''));
			const lines = filtered.map((row) => row.join(' | '));
			textarea.value = lines.join('\n');
		};

		let rows = parseLines(textarea.value);
		let columnCount = Math.max(
			minColumns,
			...rows.map((row) => (Array.isArray(row) ? row.length : 0)),
		);

		const updateColumnButtons = () => {
			removeColButton.disabled = columnCount <= minColumns;
		};

		const addRow = (cells = null) => {
			const row = document.createElement('div');
			row.className = 'service-cpt-table-row';
			const data = Array.isArray(cells) ? cells : Array(columnCount).fill('');

			data.forEach((cellValue) => {
				const input = buildCell(cellValue);
				input.addEventListener('input', syncTextarea);
				row.appendChild(input);
			});

			const remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'button';
			remove.textContent = 'Remove';
			remove.addEventListener('click', function () {
				row.remove();
				if (!rowsContainer.querySelector('.service-cpt-table-row')) {
					addRow();
				}
				syncTextarea();
			});

			row.appendChild(remove);
			rowsContainer.appendChild(row);
		};

		const renderRows = () => {
			rowsContainer.innerHTML = '';
			const normalized = normalizeRows(rows, columnCount);
			if (!normalized.length) {
				addRow();
			} else {
				normalized.forEach((row) => addRow(row));
			}
			updateColumnButtons();
			syncTextarea();
		};

		addRowButton.addEventListener('click', function () {
			addRow();
			syncTextarea();
		});

		addColButton.addEventListener('click', function () {
			columnCount += 1;
			rowsContainer.querySelectorAll('.service-cpt-table-row').forEach((row) => {
				const input = buildCell('');
				input.addEventListener('input', syncTextarea);
				row.insertBefore(input, row.lastElementChild);
			});
			updateColumnButtons();
			syncTextarea();
		});

		removeColButton.addEventListener('click', function () {
			if (columnCount <= minColumns) {
				return;
			}
			columnCount -= 1;
			rowsContainer.querySelectorAll('.service-cpt-table-row').forEach((row) => {
				const cells = row.querySelectorAll('input');
				if (cells.length > 0) {
					cells[cells.length - 1].remove();
				}
			});
			updateColumnButtons();
			syncTextarea();
		});

		rows = normalizeRows(rows, columnCount);
		renderRows();
		builder.classList.add('is-ready');
		textarea.style.display = 'none';
	});

	const relatedSearch = document.querySelector('.service-cpt-related-search');
	const relatedItems = Array.from(document.querySelectorAll('[data-related-item]'));

	if (relatedSearch && relatedItems.length) {
		const updateRelatedFilter = () => {
			const term = relatedSearch.value.trim().toLowerCase();
			relatedItems.forEach((item) => {
				const title = (item.getAttribute('data-related-title') || '').toLowerCase();
				item.style.display = !term || title.includes(term) ? 'flex' : 'none';
			});
		};

		relatedSearch.addEventListener('input', updateRelatedFilter);
	}

	const resizeEditorIframe = (editor) => {
		if (!editor || !editor.getBody) {
			return;
		}
		const body = editor.getBody();
		if (!body) {
			return;
		}
		const container = editor.getContentAreaContainer ? editor.getContentAreaContainer() : null;
		const iframe = editor.iframeElement || (container ? container.querySelector('iframe') : null);
		if (!iframe) {
			return;
		}
		const minHeight = 28;
		body.style.minHeight = '0';
		body.style.height = 'auto';
		body.style.overflow = 'hidden';
		const height = Math.max(body.scrollHeight, body.offsetHeight);
		const adjusted = Math.max(height + 2, minHeight);
		iframe.style.height = `${adjusted}px`;
		if (container) {
			container.style.height = 'auto';
		}
		const wrapper = container ? container.closest('.wp-editor-container') : null;
		if (wrapper) {
			wrapper.style.height = 'auto';
		}
	};

	const bindEditorAutoResize = (editor) => {
		if (!editor || !editor.id || !editor.id.startsWith('service_cpt_')) {
			return;
		}
		const schedule = () => {
			window.requestAnimationFrame(() => resizeEditorIframe(editor));
		};
		editor.on('init', schedule);
		editor.on('keyup', schedule);
		editor.on('change', schedule);
		editor.on('SetContent', schedule);
		editor.on('NodeChange', schedule);
		editor.on('Paste', schedule);
		editor.on('input', schedule);
		schedule();
		setTimeout(schedule, 120);
	};

	let tinymceBound = false;
	const bindTinyMce = () => {
		if (tinymceBound || !window.tinymce) {
			return;
		}
		tinymceBound = true;
		tinymce.editors.forEach(bindEditorAutoResize);
		tinymce.on('AddEditor', (event) => {
			bindEditorAutoResize(event.editor);
		});
	};
	bindTinyMce();

	const autoResizeTextarea = (textarea) => {
		if (!textarea) {
			return;
		}
		textarea.style.height = 'auto';
		textarea.style.overflow = 'hidden';
		textarea.style.height = `${textarea.scrollHeight}px`;
	};

	document.querySelectorAll('.service-cpt-section .wp-editor-area').forEach((textarea) => {
		autoResizeTextarea(textarea);
		textarea.addEventListener('input', () => autoResizeTextarea(textarea));
	});

	const resizeEditorsInSection = (section) => {
		if (!section) {
			return;
		}
		if (window.tinymce) {
			tinymce.editors.forEach((editor) => {
				const container = editor.getContainer ? editor.getContainer() : null;
				if (container && section.contains(container)) {
					resizeEditorIframe(editor);
					if (window.ResizeObserver && !editor._serviceCptObserver) {
						const body = editor.getBody ? editor.getBody() : null;
						if (body) {
							const observer = new ResizeObserver(() => resizeEditorIframe(editor));
							observer.observe(body);
							editor._serviceCptObserver = observer;
							editor.on('remove', () => observer.disconnect());
						}
					}
				}
			});
		}
		section.querySelectorAll('.wp-editor-area').forEach((textarea) => autoResizeTextarea(textarea));
	};

	sections.forEach((section) => {
		if (section.open) {
			resizeEditorsInSection(section);
		}
		section.addEventListener('toggle', () => {
			if (section.open) {
				resizeEditorsInSection(section);
			}
		});
	});

	let tinymceCheckCount = 0;
	const tinymceCheck = window.setInterval(() => {
		if (tinymceBound) {
			window.clearInterval(tinymceCheck);
			return;
		}
		if (window.tinymce) {
			bindTinyMce();
			if (tinymceBound) {
				window.clearInterval(tinymceCheck);
				return;
			}
		}
		tinymceCheckCount += 1;
		if (tinymceCheckCount > 80) {
			window.clearInterval(tinymceCheck);
		}
	}, 250);
});
