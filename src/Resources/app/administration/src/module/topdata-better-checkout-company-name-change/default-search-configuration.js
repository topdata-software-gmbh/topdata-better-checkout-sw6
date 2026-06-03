import { searchRankingPoint } from 'src/app/service/search-ranking.service';

const defaultSearchConfiguration = {
    _searchable: true,
    oldCompanyName: {
        _searchable: true,
        _score: searchRankingPoint.HIGH_SEARCH_RANKING,
    },
    newCompanyName: {
        _searchable: true,
        _score: searchRankingPoint.HIGH_SEARCH_RANKING,
    },
};

export default defaultSearchConfiguration;
