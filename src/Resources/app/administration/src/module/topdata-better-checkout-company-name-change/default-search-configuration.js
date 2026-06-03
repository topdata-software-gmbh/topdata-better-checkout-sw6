import { searchBehavior } from 'src/app/default-search-configuration';

const defaults = {
    ...searchBehavior,
    searchConfig: {
        searches: [
            {
                field: 'oldCompanyName',
                rank: 500,
            },
            {
                field: 'newCompanyName',
                rank: 500,
            },
        ],
    },
};

export default defaults;
