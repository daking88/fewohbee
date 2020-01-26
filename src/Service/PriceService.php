<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\Common\Collections\ArrayCollection;

use App\Entity\Price;
use App\Entity\ReservationOrigin;
use App\Entity\Reservation;

class PriceService
{

    private $em = null;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getPriceFromForm(Request $request, $id = 'new')
    {

        $price = new Price();

        if ($id !== 'new') {
            $price = $this->em->getRepository(Price::class)->find($id);
        }

        $price->setDescription($request->get("description-" . $id));
        $price->setPrice(str_replace(",", ".", $request->get("price-" . $id)));
        $price->setVat(str_replace(",", ".", $request->get("vat-" . $id)));
        $price->setType($request->get("type-" . $id));

        $origins = $request->get("origin-" . $id);
        if(is_array($origins)) {
            $originsDb = $this->em->getRepository(ReservationOrigin::class)->findById($origins);
            $originsPrice = $price->getReservationOrigins();
            // first remove all origins
            foreach($originsPrice as $originPrice) {
                $price->removeReservationOrigin($originPrice);
            }
            // now add all origins
            foreach($originsDb as $originDb) {
                $price->addReservationOrigin($originDb);
            }
        }

        if (strlen($request->get("seasonstart-" . $id)) != 0) {
            $price->setSeasonStart(new \DateTime($request->get("seasonstart-" . $id)));
            $price->setSeasonEnd(new \DateTime($request->get("seasonend-" . $id)));
        } else {
            $price->setSeasonStart(null);
            $price->setSeasonEnd(null);
        }

        if ($request->get("active-" . $id) != null) {
            $price->setActive(true);
        } else {
            $price->setActive(false);
        }

        if ($request->get("alldays-" . $id) != null) {
            $price->setAllDays(true);
            $price->setMonday(true);
            $price->setTuesday(true);
            $price->setWednesday(true);
            $price->setThursday(true);
            $price->setFriday(true);
            $price->setSaturday(true);
            $price->setSunday(true);
        } else {
            $noDaySelected = true;

            if ($request->get("monday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setMonday(true);
            } else {
                $price->setMonday(false);
            }

            if ($request->get("tuesday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setTuesday(true);
            } else {
                $price->setTuesday(false);
            }

            if ($request->get("wednesday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setWednesday(true);
            } else {
                $price->setWednesday(false);
            }

            if ($request->get("thursday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setThursday(true);
            } else {
                $price->setThursday(false);
            }

            if ($request->get("friday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setFriday(true);
            } else {
                $price->setFriday(false);
            }

            if ($request->get("saturday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setSaturday(true);
            } else {
                $price->setSaturday(false);
            }

            if ($request->get("sunday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setSunday(true);
            } else {
                $price->setSunday(false);
            }

            if ($noDaySelected) {
                $price->setAllDays(true);
            } else {
                $price->setAllDays(false);
            }
        }

        if ($price->getType() == 2) {
            $price->setNumberOfBeds($request->get("number-of-beds-" . $id));
            $price->setNumberOfPersons($request->get("number-of-persons-" . $id));
            $price->setMinStay($request->get("min-stay-" . $id));
        } else {
            $price->setNumberOfBeds(null);
            $price->setNumberOfPersons(null);
            $price->setMinStay(null);
        }

        return $price;
    }
    
    /**
     * Returns a list of conflicting prices
     * @param Price $price
     * @return Doctrine\Common\Collections\ArrayCollection
     */
    public function findConflictingPrices(Price $price) {
        $prices = [];
        // find conflicts when no season is given
        if($price->getSeasonStart() === null or $price->getSeasonEnd() === null) {
            $prices = $this->em->getRepository(Price::class)->findConflictingPricesWithoutPeriod($price);
        } else {
            // // find conflicts when a season is given 
            $prices = $this->em->getRepository(Price::class)->findConflictingPricesWithPeriod($price);
        }
        return new ArrayCollection($prices);
    }

    public function deletePrice($id)
    {
        $price = $this->em->getRepository(Price::class)->find($id);

        $this->em->remove($price);
        $this->em->flush();

        return true;
    }
    
    /**
     * Based on the given reservation, price categories will be returned for each day of stay ordered by priority
     * @param Reservation $reservation
     * @param int $type
     * @return array
     */
    public function getPrices(Reservation $reservation, int $type) : array {
        $days = $this->getDateDiff($reservation->getStartDate(), $reservation->getEndDate());
        if($type === 1) {
            $prices = $this->em->getRepository(Price::class)->findMiscPrices($reservation);
        } else {
            $prices = $this->em->getRepository(Price::class)->findApartmentPrices($reservation, $days);
        }        
        
        $result = [];
        $curDate = clone $reservation->getStartDate();
        for($i = 0; $i < $days; $i++) {
            $result[$i] = null;
            $curDate = $curDate->add(new \DateInterval("P".($i === 0 ? 0 : 1)."D"));
            /* @var $price Price */
            foreach($prices as $price) {
                // prices are already sorted by priority, therefore we can accept the first matching one
                // first we need to check if the current date is in between the price season
                if( $price->getSeasonStart() == null || $this->isDateBetween($curDate, $price->getSeasonStart(), $price->getSeasonEnd()) ) {
                    // second, we need to check if the weekday match                   
                    if($this->isWeekDayMatch($price, $curDate)) {
                        $result[$i] = $price;
                       // found one, go to next day
                        break;
                    }
                }
            }
        }
        
        return $result;
    }
    
    private function getDateDiff(\DateTime $start, \DateTime $end) : int {
        $interval = date_diff($start, $end);
		
        // return number of days
        return $interval->format('%a');
    }
    
    private function isDateBetween(\DateTime $cur, \DateTime $start, \DateTime $end) {
        if(($cur >= $start) && ($cur <= $end)) {
            return true;
        }
        return false;
    }
    
    private function isWeekDayMatch(Price $price, \DateTime $curr) {        
        if($price->getAllDays()) {
            return true;
        }
        
        $dayOfWeek = $curr->format("N"); // 1 = Mon, 7 = Sun
        switch ($dayOfWeek) {
            case 1:
                if($price->getMonday()) {
                    return true;
                }
                break;
            case 2:
                if($price->getTuesday()) {
                    return true;
                }
                break;
            case 3:
                if($price->getWednesday()) {
                    return true;
                }
                break;
            case 4:
                if($price->getThursday()) {
                    return true;
                }
                break;
            case 5:
                if($price->getFriday()) {
                    return true;
                }
                break;
            case 6:
                if($price->getSaturday()) {
                    return true;
                }
                break;
            case 7:
                if($price->getSunday()) {
                    return true;
                }
                break;            
        }
        return false;
    }
}
