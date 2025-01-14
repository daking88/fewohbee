<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Template;
use App\Interfaces\ITemplateRenderer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class ReservationService implements ITemplateRenderer
{
    private $em;
    private $requestStack;

    public function __construct(EntityManagerInterface $em, RequestStack $requestStack, InvoiceService $is)
    {
        $this->em = $em;
        $this->requestStack = $requestStack;
        $this->is = $is;
    }

    public function isAppartmentAlreadyBookedInCreationProcess($reservations, Appartment $appartment, \DateTimeInterface $start, \DateTimeInterface $end)
    {
        foreach ($reservations as $reservation) {
            if ($appartment->getId() == $reservation->getAppartmentId()) {
                $startReservation = strtotime($reservation->getStart());
                $endReservation = strtotime($reservation->getEnd());

                $startDateToBeChecked = $start->getTimestamp();
                $endDateToBeChecked = $end->getTimestamp();

                if (
                    (($startDateToBeChecked <= $startReservation) && ($endDateToBeChecked >= $endReservation)) ||
                    (($startDateToBeChecked <= $startReservation) && ($endDateToBeChecked <= $endReservation) && ($endDateToBeChecked > $startReservation)) ||
                    (($startDateToBeChecked >= $startReservation) && ($startDateToBeChecked < $endReservation) && ($endDateToBeChecked >= $endReservation)) ||
                    (($startDateToBeChecked >= $startReservation) && ($startDateToBeChecked <= $endReservation) && ($endDateToBeChecked > $startReservation) && ($endDateToBeChecked <= $endReservation))
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns a list of apartments that can be selected (free) for the given period.
     *
     * @param Appartment $apartment
     */
    public function getAvailableApartments(\DateTimeInterface $start, \DateTimeInterface $end, Appartment $apartment = null, string $propertyId = 'all'): array
    {
        if ($apartment instanceof Appartment) {
            $propertyId = $apartment->getObject()->getId();
        }
        $result = [];
        $appartmentsDb = $this->em->getRepository(Appartment::class)->loadAvailableAppartmentsForPeriod($start, $end, $propertyId);
        $newReservationsInformationArray = $this->requestStack->getSession()->get('reservationInCreation', []);

        if (0 != count($newReservationsInformationArray)) {
            foreach ($appartmentsDb as $apartment) {
                if (!$this->isAppartmentAlreadyBookedInCreationProcess($newReservationsInformationArray, $apartment, $start, $end)) {
                    $result[] = $apartment;
                }
            }
        } else {
            $result = $appartmentsDb;
        }

        return $result;
    }

    /**
     * Checks whether the provided apartment can be selected for the given period.
     */
    public function isApartmentSelectable(\DateTimeInterface $start, \DateTimeInterface $end, Appartment $apartment): bool
    {
        $apartments = $this->getAvailableApartments($start, $end, $apartment);
        $result = false;
        foreach ($apartments as $a) {
            if ($a->getId() === $apartment->getId()) {
                return true;
            }
        }

        return $result;
    }

    public function createReservationsFromReservationInformationArray($newReservationsInformationArray, Customer $customer = null)
    {
        $reservations = [];

        foreach ($newReservationsInformationArray as $reservationInformation) {
            $reservation = new Reservation();
            $reservation->setAppartment($this->em->getRepository(Appartment::class)->findById($reservationInformation->getAppartmentId())[0]);
            $reservation->setEndDate(new \DateTime($reservationInformation->getEnd()));
            $reservation->setStartDate(new \DateTime($reservationInformation->getStart()));
            $reservation->setReservationStatus($this->em->getRepository(ReservationStatus::class)->find($reservationInformation->getReservationStatus()));
            $reservation->setPersons($reservationInformation->getPersons());

            if (isset($customer)) {
                $reservation->setBooker($customer);
            }

            $reservations[] = $reservation;
        }

        return $reservations;
    }

    public function setCustomerInReservationInformationArray(&$newReservationsInformationArray, Customer $customer): void
    {
        foreach ($newReservationsInformationArray as $reservationInformation) {
            $reservationInformation->setCustomerId($customer->getId());
        }
    }

    public function deleteReservation($id)
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($id);

        if (0 == count($reservation->getInvoices())) {
            $customers = $reservation->getCustomers();

            foreach ($customers as $customer) {
                $reservation->removeCustomer($customer);
            }
            $this->em->persist($reservation);

            $this->em->remove($reservation);
            $this->em->flush();

            return true;
        } else {
            return false;
        }
    }

    public function updateReservation(Request $request)
    {
        $id = $request->request->get('id');
        $appartmentId = $request->request->get('aid');
        $persons = $request->request->get('persons');
        $status = $request->request->get('status');
        $start = new \DateTime($request->request->get('from'));
        $end = new \DateTime($request->request->get('end'));
        $dateInterval = date_diff($start, $end);
        // number of days
        $interval = $dateInterval->format('%a');

        /* @var $reservation \App\Entity\Reservation */
        $reservation = $this->em->getRepository(Reservation::class)->findById($id)[0];
        $appartment = $this->em->getRepository(Appartment::class)->findById($appartmentId)[0];
        $reservationStatus = $this->em->getRepository(ReservationStatus::class)->find($status);

        if ($start > $end) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }

        $reservationsArray = []; // holds the reservations that are in conflict with current reservation
        $reservationsForAppartment = $this->em->getRepository(Reservation::class)
            ->loadReservationsForPeriodForSingleAppartmentWithoutStartAndEndDate($start->getTimestamp(), $interval, $appartment);
        if (is_array($reservationsForAppartment)) {
            foreach ($reservationsForAppartment as $reservationForAppartment) {
                // we dont need the reservation that we want to update
                if ($reservationForAppartment->getId() !== $reservation->getId()) {
                    $reservationsArray[] = $reservationForAppartment;
                }
            }
        }
        // update reservation if no other stands in conflict
        if (0 == count($reservationsArray)) {
            $reservation->setStartDate($start);
            $reservation->setEndDate($end);
        }
        $reservation->setAppartment($appartment);
        $reservation->setReservationStatus($reservationStatus);
        $reservation->setPersons($persons);

        $this->em->persist($reservation);
        $this->em->flush();

        return $reservationsArray;
    }

    public function updateReservationCustomers($reservation, $customer, $tab): void
    {
        if ('booker' === $tab) {
            $reservation->setBooker($customer);
            $this->em->persist($reservation);
            $this->em->flush();
            $this->requestStack->getSession()->getFlashBag()->add('success', 'reservation.flash.update.success');
        } elseif ('guest' === $tab && count($reservation->getCustomers()) < $reservation->getPersons()) {
            // check if customer is already in list
            $isAlreadyInList = false;
            $customers = $reservation->getCustomers();
            foreach ($customers as $customerItem) {
                if ($customerItem->getId() == $customer->getId()) {
                    $isAlreadyInList = true;
                    break;
                }
            }

            if (!$isAlreadyInList) {
                // if we add a customer and room is not overbooked (more customers in it than allowed)
                $reservation->addCustomer($customer);
                $this->em->persist($reservation);
                $this->em->flush();
                $this->requestStack->getSession()->getFlashBag()->add('success', 'reservation.flash.update.success');
            } else {
                $this->requestStack->getSession()->getFlashBag()->add('warning', 'reservation.flash.update.customeralreadyinlist');
            }
        } else {
            $this->requestStack->getSession()->getFlashBag()->add('warning', 'reservation.flash.update.toomuchcustomers');
        }
    }

    /**
     * Toggles a selected or deselected price for reservations in creation and stores them in the session.
     */
    public function toggleInCreationPrice(Price $price, RequestStack $requestStack): void
    {
        $prices = $requestStack->getSession()->get('reservatioInCreationPrices', new ArrayCollection());

        $exists = $prices->exists(fn ($key, $value) => $value->getId() === $price->getId());

        if (!$exists) {
            $prices[] = $price;
        } else {
            $toDeleteKeys = $prices->filter(fn ($element) => $element->getId() === $price->getId())
            ->getKeys();
            $prices->remove($toDeleteKeys[0]);
        }

        $requestStack->getSession()->set('reservatioInCreationPrices', $prices);
    }

    /**
     * Returns an array of prices for reservations in creation.
     *
     * @return type
     */
    public function getMiscPricesInCreation(InvoiceService $is, array $reservations, PriceService $ps, RequestStack $requestStack)
    {
        // prices will be filles based on already selected prices
        if (null !== $requestStack->getSession()->get('reservatioInCreationPrices', null)) {
            $prices = $requestStack->getSession()->get('reservatioInCreationPrices');
            $requestStack->getSession()->set('invoicePositionsMiscellaneous', []);

            // assign selected prices to reservations for getting the invoice price positions based on that prices
            $reservations = $this->setPricesToReservations($prices, $reservations);
            $is->prefillMiscPositionsWithReservations($reservations, $requestStack, true);

            return $requestStack->getSession()->get('invoicePositionsMiscellaneous');
        } else { // initial load of preview new reservation, prices will be filled based on price categories
            $prices = $ps->getUniquePricesForReservations($reservations, 1);
            // prefill reservatioInCreationPrices session
            foreach ($prices as $price) {
                $this->toggleInCreationPrice($price, $requestStack);
            }
            $requestStack->getSession()->set('invoicePositionsMiscellaneous', []);
            $is->prefillMiscPositionsWithReservations($reservations, $requestStack);

            return $requestStack->getSession()->get('invoicePositionsMiscellaneous');
        }
    }

    /**
     * Adds the given prices to the reservations.
     *
     * @return array
     */
    private function setPricesToReservations(Collection $prices, array $reservations)
    {
        foreach ($reservations as $reservation) {
            foreach ($prices as $price) {
                $reservation->addPrice($price);
            }
        }

        return $reservations;
    }

    /**
     * Based on given Reservation IDs the coresponding Invoices will be returned.
     *
     * @return array
     */
    public function getInvoicesForReservationsInProgress()
    {
        $ids = $this->requestStack->getSession()->get('selectedReservationIds');
        $totalInvoices = [];
        foreach ($ids as $reservationId) {
            $reservation = $this->em->find(Reservation::class, $reservationId);
            if (!$reservation instanceof Reservation) {
                continue;
            }
            $invoices = $reservation->getInvoices();
            if (count($invoices) > 0) {
                $totalInvoices = array_merge($totalInvoices, $invoices->toArray());
            }
        }

        return $totalInvoices;
    }

    /**
     * Collects the sum of all prices for the given reservations, e.g. to be used in templates.
     */
    private function getTotalPricesForTemplate(array $reservations): array
    {
        $this->requestStack->getSession()->set('invoicePositionsMiscellaneous', []);
        $this->is->prefillMiscPositionsWithReservations($reservations, $this->requestStack, true);
        $invoicePositionsMiscellaneousArray = $this->requestStack->getSession()->get('invoicePositionsMiscellaneous');

        $this->requestStack->getSession()->set('invoicePositionsAppartments', []);
        foreach ($reservations as $reservation) {
            $this->is->prefillAppartmentPositions($reservation, $this->requestStack);
        }
        $invoicePositionsAppartmentsArray = $this->requestStack->getSession()->get('invoicePositionsAppartments');

        // collect sums
        $sumApartment = 0;
        $sumMisc = 0;
        /* @var $position \App\Entity\InvoicePosition */
        foreach ($invoicePositionsMiscellaneousArray as $position) {
            $sumMisc += $position->getTotalPriceRaw();
        }

        /* @var $position \App\Entity\InvoiceAppartment */
        foreach ($invoicePositionsAppartmentsArray as $position) {
            $sumApartment += $position->getTotalPriceRaw();
        }

        return [
            'sumApartmentRaw' => $sumApartment,
            'sumMiscRaw' => $sumMisc,
            'totalPriceRaw' => $sumApartment + $sumMisc,
            'sumApartment' => number_format($sumApartment, 2, ',', '.'),
            'sumMisc' => number_format($sumMisc, 2, ',', '.'),
            'totalPrice' => number_format($sumApartment + $sumMisc, 2, ',', '.'),
            'apartmentPositions' => $invoicePositionsAppartmentsArray,
            'miscPositions' => $invoicePositionsMiscellaneousArray,
        ];
    }

    public function getRenderParams(Template $template, mixed $param)
    {
        // params need to be an array containing a list of Reservation Objects
        $params = [
                'reservation1' => $param[0],
                'address' => (0 == count($param[0]->getBooker()->getCustomerAddresses()) ? null : $param[0]->getBooker()->getCustomerAddresses()[0]),
                'reservations' => $param,
            ];
        $prices = $this->getTotalPricesForTemplate($param);

        return array_merge($params, $prices);
    }
}
