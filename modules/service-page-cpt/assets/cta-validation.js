( function ( wp, settings ) {
	if ( ! wp || ! wp.data || ! settings ) {
		return;
	}

	const { select, dispatch, subscribe } = wp.data;
	const { __, sprintf } = wp.i18n || {
		__: ( text ) => text,
		sprintf: ( text, value ) => text.replace( '%s', value ),
	};
	const components = settings.components || {};
	const lockName = 'service-cpt-empty-ctas';
	const noticeId = 'service-cpt-empty-ctas-notice';
	let isLocked = false;
	let currentMessage = '';

	const hasText = ( value ) => {
		const text = String( value || '' ).trim();
		return text !== '' && text !== '0';
	};

	const stripHtml = ( value ) => String( value || '' ).replace( /<[^>]*>/g, '' );
	const hasCompleteLink = ( label, url ) => hasText( label ) && hasText( url );

	const getMissingCtas = ( meta ) => {
		const missing = [];
		const showHero = components.hero !== false;
		const showSidebar = components.sidebarCta !== false;
		const showWide = components.wideCta !== false;
		const showHeroFields = settings.showHeroCtaFields !== false;
		const showSidebarFields = settings.showSidebarCtaFields !== false;
		const showWideFields = settings.showWideCtaFields !== false;

		if (
			showHero &&
			showHeroFields &&
			! hasCompleteLink( meta.sp_hero_primary_label, meta.sp_hero_primary_url ) &&
			! hasCompleteLink( meta.sp_hero_secondary_label, meta.sp_hero_secondary_url )
		) {
			missing.push( __( 'Hero CTA', 'service-cpt' ) );
		}

		if (
			showSidebar &&
			showSidebarFields &&
			! hasText( meta.sp_sidebar_image ) &&
			! hasText( meta.sp_sidebar_title ) &&
			! hasText( stripHtml( meta.sp_sidebar_copy ) ) &&
			! hasCompleteLink( meta.sp_sidebar_primary_label, meta.sp_sidebar_primary_url ) &&
			! hasCompleteLink( meta.sp_sidebar_secondary_label, meta.sp_sidebar_secondary_url )
		) {
			missing.push( __( 'Sidebar CTA', 'service-cpt' ) );
		}

		const bullets = Array.isArray( meta.sp_cta_bullets ) ? meta.sp_cta_bullets : [];
		if (
			showWide &&
			showWideFields &&
			! hasText( meta.sp_cta_title ) &&
			! bullets.some( hasText ) &&
			! hasCompleteLink( meta.sp_cta_button_label, meta.sp_cta_button_url ) &&
			! hasCompleteLink( meta.sp_cta_more_text, meta.sp_cta_more_url )
		) {
			missing.push( __( 'Wide CTA', 'service-cpt' ) );
		}

		return missing;
	};

	const getMessage = ( missing ) => sprintf(
		__( 'Fill in the CTA fields before saving this service page. Missing: %s. You can also configure global CTAs in Settings > Service Pages.', 'service-cpt' ),
		missing.join( ', ' )
	);

	const clearNotice = () => {
		const notices = dispatch( 'core/notices' );
		if ( notices && notices.removeNotice && currentMessage ) {
			notices.removeNotice( noticeId );
		}
		currentMessage = '';
	};

	const unlockSaving = () => {
		const editor = dispatch( 'core/editor' );
		if ( editor && editor.unlockPostSaving && isLocked ) {
			editor.unlockPostSaving( lockName );
		}
		isLocked = false;
		clearNotice();
	};

	const updateValidation = () => {
		const editorSelect = select( 'core/editor' );
		if ( ! editorSelect || ! editorSelect.getCurrentPostType ) {
			return;
		}

		if ( editorSelect.getCurrentPostType() !== 'service_page' ) {
			unlockSaving();
			return;
		}

		const meta = editorSelect.getEditedPostAttribute
			? editorSelect.getEditedPostAttribute( 'meta' ) || {}
			: {};
		const missing = getMissingCtas( meta );

		if ( ! missing.length ) {
			unlockSaving();
			return;
		}

		const message = getMessage( missing );
		const editor = dispatch( 'core/editor' );
		const notices = dispatch( 'core/notices' );

		if ( editor && editor.lockPostSaving && ! isLocked ) {
			editor.lockPostSaving( lockName );
			isLocked = true;
		}

		if ( notices && notices.createErrorNotice && message !== currentMessage ) {
			if ( currentMessage && notices.removeNotice ) {
				notices.removeNotice( noticeId );
			}
			notices.createErrorNotice( message, {
				id: noticeId,
				isDismissible: false,
			} );
			currentMessage = message;
		}
	};

	const start = () => {
		subscribe( updateValidation );
		updateValidation();
	};

	if ( wp.domReady ) {
		wp.domReady( start );
	} else {
		setTimeout( start, 0 );
	}
}( window.wp, window.serviceCptValidation || {} ) );
