/**
 * Editor registration for the FaceVault Verify Button block.
 *
 * Plain wp.blocks / wp.element — no JSX, no build step. The block is
 * dynamic (rendered server-side); the editor shows a representative
 * static preview rather than ServerSideRender, because the real render
 * depends on the *viewer's* verification status and would confusingly
 * show the editing admin's own state.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;

	registerBlockType( 'facevault/verify-button', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var blockProps = useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Settings', 'facevault-identity-verification' ) },
						el( TextControl, {
							label: __( 'Button label', 'facevault-identity-verification' ),
							value: attributes.label,
							placeholder: __( 'Verify my identity', 'facevault-identity-verification' ),
							onChange: function ( value ) {
								props.setAttributes( { label: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Redirect after verification', 'facevault-identity-verification' ),
							help: __( 'Optional same-site URL to send verified visitors to.', 'facevault-identity-verification' ),
							value: attributes.redirect,
							placeholder: '/thanks/',
							onChange: function ( value ) {
								props.setAttributes( { redirect: value } );
							},
						} )
					)
				),
				el(
					'div',
					{ className: 'facevault-verify' },
					el(
						'button',
						{
							type: 'button',
							className: 'facevault-verify__button',
							disabled: true,
							style: {
								padding: '10px 18px',
								borderRadius: '6px',
								border: 'none',
								background: '#1f2937',
								color: '#fff',
								cursor: 'default',
							},
						},
						attributes.label || __( 'Verify my identity', 'facevault-identity-verification' )
					),
					el(
						'p',
						{ style: { fontSize: '12px', opacity: 0.7, margin: '6px 0 0' } },
						__( 'Preview — visitors see their own verification state here.', 'facevault-identity-verification' )
					)
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
