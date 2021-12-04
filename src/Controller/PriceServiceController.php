<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\Persistence\ManagerRegistry;

use App\Service\CSRFProtectionService;
use App\Service\PriceService;
use App\Entity\Price;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Entity\PricePeriod;

class PriceServiceController extends AbstractController
{
    public function __construct(private ManagerRegistry $doctrine)
    {
    }

    public function indexAction()
    {
        $em = $this->doctrine->getManager();
        $prices = $em->getRepository(Price::class)->findAllOrdered();

        return $this->render('Prices/index.html.twig', array(
            "prices" => $prices
        ));
    }

    public function getPriceAction(CSRFProtectionService $csrf, $id)
    {
        $em = $this->doctrine->getManager();
        $price = $em->getRepository(Price::class)->find($id);

        $origins = $em->getRepository(ReservationOrigin::class)->findAll();
        $categories = $em->getRepository(RoomCategory::class)->findAll();

        $originIds = Array();
        // extract ids for twig template
        foreach($price->getReservationOrigins() as $origin) {
            $originIds[] = $origin->getId();
        }

        return $this->render('Prices/price_form_edit.html.twig', array(
            'price' => $price,
            'token' => $csrf->getCSRFTokenForForm(),
            'origins' => $origins,
            'originPricesIds' => $originIds,
            'categories' => $categories
        ));
    }

    public function newPriceAction(CSRFProtectionService $csrf)
    {
        $em = $this->doctrine->getManager();

        $origins = $em->getRepository(ReservationOrigin::class)->findAll();
        $categories = $em->getRepository(RoomCategory::class)->findAll();

        $originIds = Array();
        // extract ids for twig template, all origins will be preselected
        foreach($origins as $origin) {
            $originIds[] = $origin->getId();
        }

        $price = new Price();
        $price->setId("new");

        return $this->render('Prices/price_form_create.html.twig', array(
            'price' => $price,
            'token' => $csrf->getCSRFTokenForForm(),
            'origins' => $origins,
            'originPricesIds' => $originIds,
            'categories' => $categories
        ));
    }

    public function createPriceAction(CSRFProtectionService $csrf, PriceService $ps, Request $request)
    {
        $error = false;
        $conflicts = [];
        if (($csrf->validateCSRFToken($request))) {
            $price = $ps->getPriceFromForm($request, "new");

            // check for mandatory fields
            if (strlen($price->getDescription()) == 0 || strlen($price->getPrice()) == 0 || strlen($price->getVat()) == 0
                || count($price->getReservationOrigins()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $conflicts = $ps->findConflictingPrices($price);
                
                // complain conflicts only when current price is marked as acitve
                if(!$price->getActive() || count($conflicts) === 0) {
                    $em = $this->doctrine->getManager();
                    $em->persist($price);
                    $em->flush();
                    // add succes message
                    $this->addFlash('success', 'price.flash.create.success');
                } else {
                    $error = true;
                    $this->addFlash('warning', 'price.flash.create.conflict');
                }         
            }
        }

        return $this->render('Prices/feedback.html.twig', array(
            "error" => $error,
            'conflicts' => $conflicts
        ));
    }

    public function editPriceAction(CSRFProtectionService $csrf, PriceService $ps, Request $request, $id)
    {
        $error = false;
        $conflicts = [];
        if (($csrf->validateCSRFToken($request))) {
            $price = $ps->getPriceFromForm($request, $id);
            $em = $this->doctrine->getManager();
            
            // check for mandatory fields
            if (strlen($price->getDescription()) == 0 || strlen($price->getPrice()) == 0 || strlen($price->getVat()) == 0
                || count($price->getReservationOrigins()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
                // stop auto commit of doctrine with invalid field values
                $em->clear(Price::class);
                $em->clear(PricePeriod::class);
            } else {  
                $conflicts = $ps->findConflictingPrices($price);
                // during edit we need to remove the current item from the list
                $conflicts->removeElement($price);
                
                // complain conflicts only when current price is marked as acitve
                if(!$price->getActive() || count($conflicts) === 0) {
                    $em->persist($price);
                    $em->flush();

                    // add succes message           
                    $this->addFlash('success', 'price.flash.edit.success');
                } else {
                    $error = true;
                    $this->addFlash('warning', 'price.flash.create.conflict');
                    // stop auto commit of doctrine with invalid field values
                    $em->clear(Price::class);
                    $em->clear(PricePeriod::class);
                }  
            }
        }

        return $this->render('Prices/feedback.html.twig', array(
            'error' => $error,
            'conflicts' => $conflicts
        ));
    }

    public function deletePriceAction(CSRFProtectionService $csrf, PriceService $ps, Request $request, $id)
    {
        if ($request->getMethod() == 'POST') {
            if (($csrf->validateCSRFToken($request, true))) {
                $price = $ps->deletePrice($id);
                $this->addFlash('success', 'price.flash.delete.success');
            }
            return new Response('', Response::HTTP_NO_CONTENT);
        } else {
            // initial get load (ask for deleting)           
            return $this->render('common/form_delete_entry.html.twig', array(
                "id" => $id,
                'token' => $csrf->getCSRFTokenForForm()
            ));
        }

    }
}