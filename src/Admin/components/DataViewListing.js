import { useState } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews/wp';

export default function DataViewListing( { config, label } ) {
	const fields = config.fields.map( ( field ) => ( {
		...field,
		enableSorting: field.enableSorting !== false,
	} ) );
	const [ view, setView ] = useState( {
		type: 'table',
		perPage: 20,
		page: 1,
		search: '',
		filters: [],
		fields: fields.map( ( field ) => field.id ),
		layout: {},
	} );

	return (
		<div className="onesmtp-dataviews-shell" aria-label={ label }>
			<DataViews
				data={ config.data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				defaultLayouts={ { table: {} } }
				paginationInfo={ {
					totalItems: config.data.length,
					totalPages: Math.max(
						1,
						Math.ceil( config.data.length / view.perPage )
					),
				} }
			/>
		</div>
	);
}
