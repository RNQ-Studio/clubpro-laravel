<?php

return [
    // Bound DOB validation while allowing communities to choose a smaller age span.
    'max_age_years' => max(18, min(150, (int) env('COMMUNITY_MEMBER_MAX_AGE_YEARS', 120))),
];
