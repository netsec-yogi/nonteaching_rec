<?php

namespace App\Models;

class PostEligibilityCriterion extends BaseModel
{
    protected $table = 'post_eligibility_criteria';

    public const TYPES = ['min_percentage', 'min_experience_months', 'max_age', 'qualification_level', 'custom'];

    protected $casts = ['is_essential' => 'boolean'];
}
