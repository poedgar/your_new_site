import { __, sprintf } from '@wordpress/i18n';

const SPECIFIC_CONDITION = 'specifics';
const CONDITION_GROUPS = [ 'show_on', 'not_show_on' ];

/**
 * Whether a display-condition group selects "Specific Pages" but has no page chosen.
 *
 * @param {Object} group A schema `show_on` / `not_show_on` group ({ rules, specific }).
 * @return {boolean} True when the "Specific Pages" rule is set with no page selected.
 */
const isMissingSpecificPages = ( group ) => {
	const rules = Array.isArray( group?.rules ) ? group.rules : [];
	if ( ! rules.includes( SPECIFIC_CONDITION ) ) {
		return false;
	}

	const specific = Array.isArray( group?.specific ) ? group.specific : [];
	return specific.length === 0;
};

/**
 * Validate that every schema using the "Specific Pages" display condition has
 * at least one page selected. Mirrors the shape of validateCustomJsonLdSchemas
 * so it can gate the global save button the same way.
 *
 * @param {Object} schemas Map of schema id => schema settings.
 * @return {{valid: boolean, message: string}} Validation result.
 */
export const validateSchemaDisplayConditions = ( schemas = {} ) => {
	for ( const schema of Object.values( schemas || {} ) ) {
		for ( const groupKey of CONDITION_GROUPS ) {
			if ( ! isMissingSpecificPages( schema?.[ groupKey ] ) ) {
				continue;
			}

			const label =
				schema?.fields?.schema_name ||
				schema?.title ||
				__( 'Schema', 'surerank' );
			return {
				valid: false,
				message: sprintf(
					/* translators: %s: schema name. */
					__(
						'%s: select at least one page/post/taxonomy for the "Specific Pages" display condition.',
						'surerank'
					),
					label
				),
			};
		}
	}

	return { valid: true, message: '' };
};
