<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Entity\Product;
use App\Catalog\Form\ProductType;
use App\Catalog\Repository\ProductRepository;
use App\Shop\Service\CurrentShop;
use App\Shop\Service\ImageStorage;
use App\Shop\Service\OnboardingNudge;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pro/produits')]
final class ProductController extends AbstractController
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $entityManager,
        private readonly ImageStorage $imageStorage,
    ) {
    }

    #[Route('', name: 'pro_product_index', methods: ['GET'])]
    public function index(): Response
    {
        $permanent = $seasonal = $archived = [];

        foreach ($this->products->findByShop($this->currentShop->get(), includeArchived: true) as $product) {
            if ($product->isArchived()) {
                $archived[] = $product;
            } elseif ($product->isSeasonal()) {
                $seasonal[] = $product;
            } else {
                $permanent[] = $product;
            }
        }

        return $this->render('catalog/product/index.html.twig', [
            'permanent' => $permanent,
            'seasonal' => $seasonal,
            'archived' => $archived,
        ]);
    }

    #[Route('/nouveau', name: 'pro_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, OnboardingNudge $onboardingNudge): Response
    {
        $shop = $this->currentShop->get();
        $product = new Product($shop);
        $product->setPosition($this->products->count(['shop' => $shop]) + 1);

        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($product);
            $this->save($product, $form);
            $this->addFlash('success', \sprintf('« %s » est ajouté à ton catalogue.', $product->getName()));
            $onboardingNudge->suggestNextStep($shop);

            return $this->redirectToRoute('pro_product_index');
        }

        return $this->render('catalog/product/form.html.twig', ['product' => $product, 'form' => $form]);
    }

    #[Route('/{id}/modifier', name: 'pro_product_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $product = $this->findProduct($id);
        $form = $this->createForm(ProductType::class, $product, ['has_photo' => null !== $product->getPhotoPath()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->save($product, $form);
            $this->addFlash('success', \sprintf('« %s » est à jour.', $product->getName()));

            return $this->redirectToRoute('pro_product_index');
        }

        return $this->render('catalog/product/form.html.twig', ['product' => $product, 'form' => $form]);
    }

    /**
     * Archives an active product, or puts an archived one back in the catalogue.
     */
    #[Route('/{id}/archiver', name: 'pro_product_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(int $id, Request $request): RedirectResponse
    {
        $product = $this->findProduct($id);

        if (!$this->isCsrfTokenValid('product_archive_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $product->setArchived(!$product->isArchived());
        $this->entityManager->flush();

        $this->addFlash('success', $product->isArchived()
            ? \sprintf('« %s » est archivé : il ne sera plus proposé dans tes nouvelles ventes.', $product->getName())
            : \sprintf('« %s » est de retour dans ton catalogue.', $product->getName()));

        return $this->redirectToRoute('pro_product_index');
    }

    /**
     * Moves a product one step up or down among the active products of the same kind (permanent range
     * or "du moment"), then renumbers the shop's active products so that positions stay distinct.
     */
    #[Route('/{id}/deplacer', name: 'pro_product_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(int $id, Request $request): RedirectResponse
    {
        $product = $this->findProduct($id);

        if (!$this->isCsrfTokenValid('product_move_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $all = $this->products->findByShop($product->getShop());
        $group = array_values(array_filter($all, static fn (Product $other): bool => $other->getKind() === $product->getKind()));
        $index = array_search($product, $group, true);
        $neighbour = false === $index ? null : ($group[$index + ('up' === $request->request->getString('direction') ? -1 : 1)] ?? null);
        $from = array_search($product, $all, true);
        $to = null === $neighbour ? false : array_search($neighbour, $all, true);

        // An archived product, or the first / last of its group, does not move.
        if (false !== $from && false !== $to) {
            [$all[$from], $all[$to]] = [$all[$to], $all[$from]];

            foreach ($all as $position => $item) {
                $item->setPosition($position + 1);
            }
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('pro_product_index', status: Response::HTTP_SEE_OTHER);
    }

    private function findProduct(int $id): Product
    {
        return $this->products->findOneInShop($id, $this->currentShop->get())
            ?? throw $this->createNotFoundException('Product not found in the current shop.');
    }

    /**
     * @param FormInterface<Product> $form
     */
    private function save(Product $product, FormInterface $form): void
    {
        if ($product->isPermanent()) {
            // The availability period only makes sense for a seasonal product.
            $product->setAvailableFrom(null)->setAvailableUntil(null);
        }

        $this->entityManager->flush();

        $previousPhoto = $product->getPhotoPath();
        $photo = $form->get('photo')->getData();

        if ($photo instanceof UploadedFile) {
            $product->setPhotoPath($this->imageStorage->store($photo, 'products/'.$product->getShop()->getId()));
        } elseif ($form->has('removePhoto') && true === $form->get('removePhoto')->getData()) {
            $product->setPhotoPath(null);
        }

        if ($previousPhoto !== $product->getPhotoPath()) {
            $this->entityManager->flush();
            $this->imageStorage->remove($previousPhoto);
        }
    }
}
