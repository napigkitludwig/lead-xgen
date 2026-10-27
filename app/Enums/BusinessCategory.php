<?php

namespace App\Enums;

enum BusinessCategory: string
{
    case DentalClinic = 'Dental Clinic';
    case Dentist = 'Dentist';
    case CosmeticDentist = 'Cosmetic Dentist';
    case PediatricDentist = 'Pediatric Dentist';
    case Orthodontist = 'Orthodontist';
    case Periodontist = 'Periodontist';
    case Endodontist = 'Endodontist';
    case Prosthodontist = 'Prosthodontist';
    case OralSurgeon = 'Oral and Maxillofacial Surgeon';
    case DentalLab = 'Dental Laboratory';
    case DentalHygiene = 'Dental Hygiene Service';
    case ImplantSpecialist = 'Dental Implant Specialist';
    case TeethWhitening = 'Teeth Whitening Service';
    case EmergencyDentist = 'Emergency Dentist';
    case DentureClinic = 'Denture Clinic';
    case DentalSupplier = 'Dental Supply Store';

    /**
     * Categories suggested on top of the original brief.
     */
    public function isRecommended(): bool
    {
        return in_array($this, [
            self::ImplantSpecialist,
            self::TeethWhitening,
            self::EmergencyDentist,
            self::DentureClinic,
            self::DentalSupplier,
        ], true);
    }
}
