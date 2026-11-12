<?php

declare(strict_types=1);

namespace App\Tests\Recurring;

use App\Catalog\Entity\Product;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Enum\RecurringMode;
use App\Recurring\Enum\RecurringTerm;
use App\Recurring\Form\RecurringOrderFormData;
use App\Recurring\Form\RecurringOrderType;
use App\Recurring\Form\RecurringPlanType;
use App\Shared\Service\Quantity;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ProductFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Zenstruck\Foundry\Test\Factories;

/**
 * The fields of the plan and subscription forms, and what they refuse.
 */
final class RecurringFormsTest extends KernelTestCase
{
    use Factories;
    use RecurringScenario;

    public function testThePlanFormFields(): void
    {
        [$plan, $bread, $tomatoes] = $this->planWithTwoProducts();

        $view = $this->planForm($plan, [$bread, $tomatoes])->createView();

        self::assertSame([
            'enabled' => ['Ouvrir les inscriptions', 'Le panier apparaît sur ta page et sur celle des ventes. Fermer les inscriptions n\'arrête pas les abonnements en cours.', false, []],
            'title' => ['Nom du panier', null, true, ['placeholder' => 'Panier de légumes de la semaine']],
            'description' => ['Description', null, false, ['rows' => 2]],
            'mode' => ['Composition', null, true, []],
            'maxShares' => ['Paniers par abonné, au plus', 'Panier composé : un abonné peut prendre plusieurs paniers à chaque livraison.', true, ['min' => 1, 'max' => 10, 'inputmode' => 'numeric']],
            'capacity' => ['Nombre de places', 'Vide : sans limite. Les abonnements suspendus gardent leur place.', false, ['min' => 1, 'inputmode' => 'numeric']],
            'term' => ['Engagement', null, true, []],
            'seasonStartsOn' => ['Première livraison du contrat', null, false, []],
            'seasonEndsOn' => ['Dernière livraison du contrat', null, false, []],
            'noticeDays' => ['Préavis de résiliation (jours)', 'Sans engagement : les livraisons de ces jours restent dues après une résiliation. 0 : résiliation immédiate.', true, ['min' => 0, 'max' => 60, 'inputmode' => 'numeric']],
            'maxPauses' => ['Absences possibles par abonné', 'Nombre de livraisons qu\'un abonné peut mettre en pause. Vide : autant qu\'il veut.', false, ['min' => 0, 'inputmode' => 'numeric']],
            'conditions' => ['Conditions affichées aux abonnés', 'Ce que tu attends d\'eux : panier oublié, prévenir d\'une absence, paiement à chaque retrait…', false, ['rows' => 4]],
            'quantities' => [false, null, true, []],
        ], $this->fields($view));
        self::assertSame([
            (string) $bread->getId() => ['Pain', null, false, ['inputmode' => 'numeric', 'class' => 'max-w-32']],
            (string) $tomatoes->getId() => ['Tomates', null, false, ['inputmode' => 'decimal', 'class' => 'max-w-32']],
        ], $this->fields($view['quantities']));
        self::assertSame(['Panier composé', 'Produits au choix'], $this->choiceLabels($view['mode']));
        self::assertSame(['Sans engagement', 'Contrat de saison'], $this->choiceLabels($view['term']));
        self::assertSame('date', $view['seasonStartsOn']->vars['type']);
        // Prefilled with the quantities of the plan.
        self::assertSame('1', $view['quantities'][(string) $bread->getId()]->vars['value']);
        self::assertSame('', $view['quantities'][(string) $tomatoes->getId()]->vars['value']);
    }

    public function testThePlanFormKeepsTheTypedQuantitiesInOrder(): void
    {
        [$plan, $bread, $tomatoes] = $this->planWithTwoProducts();
        $form = $this->planForm($plan, [$tomatoes, $bread]);

        $form->submit($this->planData([(string) $tomatoes->getId() => '1,25', (string) $bread->getId() => '2']));

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame([1250, 0], [$plan->getItemFor($tomatoes)?->getQuantity(), $plan->getItemFor($tomatoes)?->getPosition()]);
        self::assertSame([2000, 1], [$plan->getItemFor($bread)?->getQuantity(), $plan->getItemFor($bread)?->getPosition()]);
    }

    public function testThePlanFormRefusesWhatCannotBeDelivered(): void
    {
        [$plan, $bread, $tomatoes] = $this->planWithTwoProducts();
        $form = $this->planForm($plan, [$bread, $tomatoes]);

        $form->submit($this->planData([(string) $bread->getId() => '1,5', (string) $tomatoes->getId() => '99,001']));

        self::assertFalse($form->isValid());
        self::assertSame(['Indique un nombre entier.'], $this->errors($form->get('quantities')->get((string) $bread->getId())));
        self::assertSame(['99 au plus.'], $this->errors($form->get('quantities')->get((string) $tomatoes->getId())));
        self::assertSame(Quantity::units(1), $plan->getItemFor($bread)?->getQuantity(), 'An invalid quantity leaves the plan as it was.');
        self::assertNull($plan->getItemFor($tomatoes));

        $form = $this->planForm($plan, [$bread, $tomatoes]);
        $form->submit($this->planData([(string) $bread->getId() => '0', (string) $tomatoes->getId() => '99']));
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $item = $plan->getItems()->filter(static fn ($item): bool => $item->getProduct() === $tomatoes)->first();
        self::assertSame(99_000, $item ? $item->getQuantity() : null);
        self::assertNull($plan->getItemFor($bread));
    }

    public function testThePlanFormChecksTheSeason(): void
    {
        [$plan, $bread] = $this->planWithTwoProducts();
        $form = $this->planForm($plan, [$bread]);
        // A Wednesday recurrence: from Thursday to Tuesday there is no delivery.
        $thursday = $plan->getSchedule()->nextDeliveryDates(self::now())[0]->modify('+1 day');

        $form->submit($this->planData([(string) $bread->getId() => '1'], ['term' => 'season', 'seasonStartsOn' => $thursday->format('Y-m-d'), 'seasonEndsOn' => $thursday->modify('+5 days')->format('Y-m-d')]));

        self::assertSame([], $this->errors($form->get('seasonStartsOn')));
        self::assertSame([\sprintf('Le contrat doit compter au moins une livraison (le %s).', $plan->weekdayName())], $this->errors($form->get('seasonEndsOn')));
        self::assertSame(RecurringTerm::Season, $plan->getTerm());
    }

    public function testTheSubscriptionFormOfABasket(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $wine = ProductFactory::new()->alcoholic()->create(['shop' => $shop, 'name' => 'Cornas']);
        $plan = self::basketPlan($shop, [[$wine, Quantity::units(1)]]);
        $plan->getSchedule()->addDeliveryPoint(DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Ancien', 'archived' => true]));

        $view = $this->orderForm($plan, ['subscribe' => true, 'terms' => true])->createView();

        self::assertSame([
            'deliveryPoint' => ['Point de retrait', null, true, []],
            'deliveryAddress' => ['Adresse de livraison', null, false, ['rows' => 2, 'autocomplete' => 'street-address', 'placeholder' => 'Numéro, rue, code postal, commune']],
            'shares' => ['Nombre de paniers à chaque livraison', null, true, ['min' => 1, 'max' => 3, 'inputmode' => 'numeric']],
            'acceptConditions' => ['J\'ai lu les conditions de cet abonnement.', null, true, []],
            'adultCertified' => ['Je certifie avoir 18 ans ou plus', null, true, []],
            'acceptTerms' => ['J\'accepte les <a href="/cgu#clients" class="link" target="_blank" rel="noopener">conditions d\'utilisation</a> et j\'ai lu la <a href="/confidentialite" class="link" target="_blank" rel="noopener">politique de confidentialité</a>.', null, true, []],
        ], $this->fields($view));
        self::assertTrue($view['acceptTerms']->vars['label_html']);
        self::assertTrue($view['deliveryPoint']->vars['expanded']);
        self::assertSame(['Place du marché'], $this->choiceLabels($view['deliveryPoint']), 'Archived points are left out.');
        self::assertTrue($view['deliveryPoint']->children[0]->vars['checked'], 'The only point is chosen.');
        self::assertArrayNotHasKey('adultCertified', $this->fields($this->orderForm(self::basketPlan(), ['subscribe' => true])->createView()));
        self::assertSame(['deliveryPoint', 'deliveryAddress', 'shares'], array_keys($this->fields($this->orderForm($plan)->createView())));
    }

    public function testTheSubscriptionFormOfASeasonInChoiceMode(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $cheese = ProductFactory::new()->byWeight(250, 500)->create(['shop' => $shop, 'name' => 'Tomme']);
        $picodon = ProductFactory::createOne(['shop' => $shop, 'name' => 'Picodon']);
        $plan = self::basketPlan($shop, [[$cheese, 1500], [$picodon, Quantity::units(6)]], RecurringMode::Choice);
        $plan->setTerm(RecurringTerm::Season);

        $form = $this->orderForm($plan, ['subscribe' => true]);
        $view = $form->createView();

        self::assertSame('J\'ai lu les conditions et je m\'engage pour toute la durée du contrat.', $view['acceptConditions']->vars['label']);
        self::assertSame([
            (string) $cheese->getId() => ['Tomme', '1,5 kg au plus, par 0,25 kg', false, ['inputmode' => 'decimal', 'class' => 'max-w-32']],
            (string) $picodon->getId() => ['Picodon', '6 au plus', false, ['inputmode' => 'numeric', 'class' => 'max-w-32']],
        ], $this->fields($view['quantities']));
        self::assertSame('1,5', $view['quantities'][(string) $cheese->getId()]->vars['value'], 'Prefilled with what the producer proposes.');

        $form->submit(['deliveryPoint' => (string) self::first($plan->getSchedule()->getDeliveryPoints())->getId(), 'quantities' => [(string) $cheese->getId() => '1,75', (string) $picodon->getId() => '7'], 'acceptConditions' => '1']);
        self::assertSame(['1,5 kg au plus.'], $this->errors($form->get('quantities')->get((string) $cheese->getId())));
        self::assertSame(['6 au plus.'], $this->errors($form->get('quantities')->get((string) $picodon->getId())));

        $form = $this->orderForm($plan, ['subscribe' => true]);
        $form->submit(['deliveryPoint' => (string) self::first($plan->getSchedule()->getDeliveryPoints())->getId(), 'quantities' => [(string) $cheese->getId() => '0,25', (string) $picodon->getId() => 'deux'], 'acceptConditions' => '1']);
        self::assertSame(['Choisis un multiple de 0,25 kg, 0,5 kg au moins.'], $this->errors($form->get('quantities')->get((string) $cheese->getId())));
        self::assertSame(['Indique un nombre entier.'], $this->errors($form->get('quantities')->get((string) $picodon->getId())));

        $form = $this->orderForm($plan, ['subscribe' => true]);
        $form->submit(['deliveryPoint' => (string) self::first($plan->getSchedule()->getDeliveryPoints())->getId(), 'quantities' => [(string) $cheese->getId() => '', (string) $picodon->getId() => '0']]);
        self::assertSame(['Choisis au moins un produit.'], $this->errors($form));
        self::assertSame(['Lis et accepte les conditions de l\'abonnement pour continuer.'], $this->errors($form->get('acceptConditions')));

        $form = $this->orderForm($plan);
        $form->submit(['deliveryPoint' => (string) self::first($plan->getSchedule()->getDeliveryPoints())->getId(), 'quantities' => [(string) $cheese->getId() => '0,5', (string) $picodon->getId() => '6']]);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $data = $form->getData();
        self::assertInstanceOf(RecurringOrderFormData::class, $data);
        self::assertSame([(int) $cheese->getId() => 500, (int) $picodon->getId() => 6000], $data->toRequest()->quantities);
    }

    public function testTheSubscriptionFormChecksThePickUp(): void
    {
        $plan = self::basketPlan();
        $home = DeliveryPointFactory::new()->home()->create(['shop' => $plan->getShop()]);
        $plan->getSchedule()->addDeliveryPoint($home);

        $form = $this->orderForm($plan);
        $form->submit(['deliveryPoint' => (string) $home->getId(), 'deliveryAddress' => '  ', 'shares' => '4']);
        self::assertSame(['Indique ton adresse pour la livraison à domicile.'], $this->errors($form->get('deliveryAddress')));
        self::assertSame(['Choisis entre 1 et 3 paniers.'], $this->errors($form->get('shares')));

        $form = $this->orderForm($plan);
        $form->submit(['deliveryPoint' => '999999', 'shares' => '1']);
        self::assertSame(['Ce point de retrait n\'est pas proposé pour ce panier.'], $this->errors($form->get('deliveryPoint')));

        $form = $this->orderForm($plan);
        $form->submit(['shares' => '1']);
        self::assertSame(['Choisis où récupérer tes paniers.'], $this->errors($form->get('deliveryPoint')));

        $form = $this->orderForm($plan);
        $form->submit(['deliveryPoint' => (string) $home->getId(), 'deliveryAddress' => str_repeat('a', 501), 'shares' => '1']);
        self::assertSame(['Cette adresse est trop longue (500 caractères maximum).'], $this->errors($form->get('deliveryAddress')));
    }

    public function testTheFormDataOfASubscription(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $cheese = ProductFactory::createOne(['shop' => $shop, 'name' => 'Picodon']);
        $plan = self::basketPlan($shop, [[$cheese, Quantity::units(6)]], RecurringMode::Choice);
        $second = DeliveryPointFactory::createOne(['shop' => $shop]);
        $recurringOrder = self::subscription($plan, quantities: [(int) $cheese->getId() => Quantity::units(2)])->setShares(2)->setDeliveryAddress('chez moi');

        $data = RecurringOrderFormData::fromRecurringOrder($recurringOrder);
        self::assertSame($recurringOrder->getDeliveryPoint(), $data->deliveryPoint);
        self::assertSame('chez moi', $data->deliveryAddress);
        self::assertSame(2, $data->shares);
        self::assertSame([(string) $cheese->getId() => 2000], $data->quantities);

        self::assertSame(self::first($plan->getSchedule()->getDeliveryPoints()), RecurringOrderFormData::forPlan($plan)->deliveryPoint);
        $plan->getSchedule()->addDeliveryPoint($second);
        self::assertNull(RecurringOrderFormData::forPlan($plan)->deliveryPoint, 'Two points: the customer chooses.');
        self::assertSame([(string) $cheese->getId() => 6000], RecurringOrderFormData::forPlan($plan)->quantities);

        $this->expectException(\LogicException::class);
        new RecurringOrderFormData()->toRequest();
    }

    /**
     * @return array{RecurringPlan, Product, Product}
     */
    private function planWithTwoProducts(): array
    {
        $shop = self::shopOf(self::subscribedProducer());
        $bread = ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain']);
        $tomatoes = ProductFactory::new()->byWeight(250)->create(['shop' => $shop, 'name' => 'Tomates']);
        $plan = self::basketPlan($shop, [[$bread, Quantity::units(1)]]);

        return [$plan, $bread, $tomatoes];
    }

    /**
     * @param list<Product> $products
     *
     * @return FormInterface<RecurringPlan>
     */
    private function planForm(RecurringPlan $plan, array $products): FormInterface
    {
        return self::getContainer()->get(FormFactoryInterface::class)->create(RecurringPlanType::class, $plan, ['products' => $products, 'csrf_protection' => false]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return FormInterface<RecurringOrderFormData>
     */
    private function orderForm(RecurringPlan $plan, array $options = []): FormInterface
    {
        return self::getContainer()->get(FormFactoryInterface::class)->create(RecurringOrderType::class, RecurringOrderFormData::forPlan($plan), ['plan' => $plan, 'csrf_protection' => false, ...$options]);
    }

    /**
     * @param array<int|string, string> $quantities
     * @param array<string, string>     $fields
     *
     * @return array<string, mixed>
     */
    private function planData(array $quantities, array $fields = []): array
    {
        return ['title' => 'Panier', 'mode' => 'basket', 'maxShares' => '1', 'term' => 'open_ended', 'noticeDays' => '0', 'quantities' => $quantities, ...$fields];
    }

    /**
     * Label, help, required and attributes of each child.
     *
     * @return array<int|string, array{mixed, mixed, mixed, mixed}>
     */
    private function fields(FormView $view): array
    {
        $fields = [];
        foreach ($view->children as $name => $child) {
            $fields[$name] = [$child->vars['label'], $child->vars['help'], $child->vars['required'], $child->vars['attr']];
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function choiceLabels(FormView $view): array
    {
        $choices = $view->vars['choices'];
        self::assertIsArray($choices);
        $labels = [];
        foreach ($choices as $choice) {
            self::assertInstanceOf(ChoiceView::class, $choice);
            self::assertIsString($choice->label);
            $labels[] = $choice->label;
        }

        return $labels;
    }

    /**
     * @template T
     *
     * @param FormInterface<T> $form
     *
     * @return list<string>
     */
    private function errors(FormInterface $form): array
    {
        $messages = [];
        foreach ($form->getErrors() as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }
}
