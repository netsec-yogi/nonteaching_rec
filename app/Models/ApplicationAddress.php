<?php

namespace App\Models;

class ApplicationAddress extends BaseModel
{
    public function formatted(): string
    {
        return collect([$this->line1, $this->line2, $this->city, $this->district, $this->state,
            $this->pincode ? 'PIN '.$this->pincode : null, $this->country])
            ->filter()->implode(', ');
    }
}
