<?php

namespace App\Controller\Rest;

use App\Entity\Clan;
use App\Entity\User;
use App\Entity\UserClan;
use App\Repository\ClanRepository;
use App\Repository\UserRepository;
use App\Serializer\UserClanNormalizer;
use App\Service\ClanService;
use App\Transfer\AuthObject;
use App\Transfer\Bulk;
use App\Transfer\Error;
use App\Transfer\PaginationCollection;
use App\Transfer\UuidObject;
use App\Transfer\ValidationError;
use Doctrine\ORM\EntityManagerInterface;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Request\ParamFetcher;
use FOS\RestBundle\View\View;
use Nelmio\ApiDocBundle\Annotation\Model;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use OpenApi\Annotations as OA;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Class ClanController.
 */
#[Rest\Route('/clans')]
class ClanController extends AbstractFOSRestController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClanService $clanService,
        private readonly ClanRepository $clanRepository,
        private readonly UserRepository $userRepository,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator
    ) {
    }

    private function deserializeAndValidate(Request $request, string $type, array $groups = ['Default'], ?object $objectToPopulate = null): mixed
    {
        $context = ['allow_extra_attributes' => false];
        if ($objectToPopulate) {
            $context['object_to_populate'] = $objectToPopulate;
        }

        $object = $this->serializer->deserialize(
            $request->getContent(),
            $type,
            'json',
            $context
        );

        $violations = $this->validator->validate($object, null, $groups);

        if (count($violations) > 0) {
            return $this->handleValidiationErrors($violations) ?? $object;
        }

        return $object;
    }

    private function handleValidiationErrors(ConstraintViolationListInterface $errors): ?View
    {
        if (count($errors) == 0) {
            return null;
        }

        $error = $errors[0];
        if ($error->getConstraint() instanceof UniqueEntity) {
            return $this->view(ValidationError::withProperty($error->getPropertyPath(), 'UniqueEntity'), Response::HTTP_CONFLICT);
        } else {
            return $this->view(ValidationError::withProperty($error->getPropertyPath(), 'Assert'), Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Returns a single Clan object.
     *
     * @OA\Response(
     *     response=200,
     *     description="Returns the Clan",
     *     @OA\Schema(type="object", ref=@Model(type=\App\Entity\Clan::class, groups={"read"}))
     * )
     * @OA\Response(
     *     response=404,
     *     description="Returns if the clan does not exitst"
     * )
     * @OA\Parameter(
     *     name="uuid",
     *     in="path",
     *     description="the UUID of the clan to query",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     * )
     * @OA\Tag(name="Clan")
     */
    #[Rest\Get('/{uuid}', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    #[Rest\QueryParam(name: 'depth', requirements: '\d+', default: 2, allowBlank: false)]
    public function getClanAction(string $uuid, ParamFetcher $fetcher): Response
    {
        $clan = $this->clanRepository->findOneBy(['uuid' => $uuid]);
        if (!$clan) {
            return $this->handleView($this->view(null, Response::HTTP_NOT_FOUND));
        }

        $depth = intval($fetcher->get('depth'));
        $view = $this->view($clan);
        $view->getContext()->setAttribute(UserClanNormalizer::DEPTH, $depth);

        return $this->handleView($view);
    }

    /**
     * Creates a Clan.
     *
     * @OA\Response(
     *     response=201,
     *     description="The edited clan",
     * )
     * @OA\Response(
     *     response=400,
     *     description="Returns if the body was invalid"
     * )
     * @OA\Response(
     *     response=409,
     *     description="Returns if the body was invalid"
     * )
     * @OA\RequestBody(
     *     description="the updated clan field as JSON",
     *     required=true,
     *     @OA\Schema(type="object", format="application/json", ref=@Model(type=\App\Entity\Clan::class, groups={"write"}))
     * )
     * @OA\Tag(name="Clan")
     */
    #[Rest\Post('')]
    public function createClanAction(Request $request): Response
    {
        $new = $this->deserializeAndValidate($request, Clan::class, ['Transfer', 'Create', 'Unique']);
        if ($new instanceof View) {
            return $this->handleView($new);
        }

        $hasher = $this->hasherFactory->getPasswordHasher(Clan::class);
        $new->setJoinPassword($hasher->hash($new->getJoinPassword()));

        $this->em->persist($new);
        $this->em->flush();

        $view = $this->view($new, Response::HTTP_CREATED);

        return $this->handleView($view);
    }

    /**
     * Edits a clan.
     *
     * @OA\Response(
     *     response=200,
     *     description="The edited clan",
     *     @OA\Schema(type="object", ref=@Model(type=\App\Entity\Clan::class, groups={"read"}))
     * )
     * @OA\Response(
     *     response=404,
     *     description="Returns if the clan does not exitst"
     * )
     * @OA\Response(
     *     response=400,
     *     description="Returns if the body was invalid"
     * )
     * @OA\Parameter(
     *     name="uuid",
     *     in="path",
     *     description="the UUID of the clan to modify",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     * )
     * @OA\RequestBody(
     *     description="the updated clan field as JSON",
     *     required=true,
     *     @OA\Schema(type="object", format="application/json", ref=@Model(type=\App\Entity\Clan::class, groups={"write"}))
     * )
     * @OA\Tag(name="Clan")
     */
    #[Rest\Patch('/{uuid}', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function editClanAction(string $uuid, Request $request): Response
    {
        $clan = $this->clanRepository->findOneBy(['uuid' => $uuid]);
        if (!$clan) {
            return $this->handleView($this->view(null, Response::HTTP_NOT_FOUND));
        }

        $update = $this->deserializeAndValidate($request, Clan::class, ['Transfer', 'Unique'], $clan);
        if ($update instanceof View) {
            return $this->handleView($update);
        }

        $hasher = $this->hasherFactory->getPasswordHasher(Clan::class);

        if ($update->getJoinPassword() && $hasher->needsRehash($update->getJoinPassword())) {
            $update->setJoinPassword($hasher->hash($update->getJoinPassword()));
        }

        $this->em->persist($update);
        $this->em->flush();

        return $this->handleView($this->view($update));
    }

    /**
     * Delete a Clan.
     */
    #[Rest\Delete('/{uuid}', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function removeClanAction(string $uuid): Response
    {
        $clan = $this->clanRepository->findOneBy(['uuid' => $uuid]);
        if (!$clan) {
            return $this->handleView($this->view(null, Response::HTTP_NOT_FOUND));
        }

        $this->em->remove($clan);
        $this->em->flush();

        $view = $this->view(null, Response::HTTP_NO_CONTENT);

        return $this->handleView($view);
    }

    /**
     * Returns all Clan objects with filter.
     */
    #[Rest\Get('')]
    #[Rest\QueryParam(name: 'page', requirements: '\d+', default: 1)]
    #[Rest\QueryParam(name: 'limit', requirements: '\d+', default: 10)]
    #[Rest\QueryParam(name: 'filter')]
    #[Rest\QueryParam(name: 'sort', requirements: '(asc|desc)', map: true)]
    #[Rest\QueryParam(name: 'exact', requirements: '(true|false)', default: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'case', requirements: '(true|false)', default: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'depth', requirements: '\d+', default: 2, allowBlank: false)]
    public function getClansAction(ParamFetcher $fetcher): Response
    {
        $page = intval($fetcher->get('page'));
        $limit = intval($fetcher->get('limit'));
        $filter = $fetcher->get('filter');
        $sort = $fetcher->get('sort');
        $exact = $fetcher->get('exact');
        $case = $fetcher->get('case');
        $depth = intval($fetcher->get('depth'));

        $sort = is_array($sort) ? $sort : (empty($sort) ? [] : [$sort => 'asc']);
        $case = $case === 'true';
        $exact = $exact === 'true';

        if (is_array($filter)) {
            $qb = $this->clanRepository->findAllQueryBuilder($filter, $sort, $case, $exact);
        } else {
            $qb = $this->clanRepository->findAllSimpleQueryBuilder($filter, $sort, $case, $exact);
        }

        // set useOutputWalker to false otherwise we cannot Paginate Entities with INNER/LEFT Joins
        $pager = new Pagerfanta(new QueryAdapter($qb, true, false));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        $clans = [];
        foreach ($pager->getCurrentPageResults() as $clan) {
            $clans[] = $clan;
        }

        $collection = new PaginationCollection(
            $clans,
            $pager->getNbResults()
        );

        $view = $this->view($collection);
        $view->getContext()->setAttribute(UserClanNormalizer::DEPTH, $depth);

        return $this->handleView($view);
    }

    /**
     * Adds a User to a Clan.
     */
    #[Rest\Post('/{uuid}/users', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function addMemberAction(string $uuid, Request $request): Response
    {
        $clan = $this->clanRepository->findOneBy(['uuid' => $uuid]);
        if (!$clan) {
            return $this->handleView($this->view(null, Response::HTTP_NOT_FOUND));
        }

        $user_uuid = $this->deserializeAndValidate($request, UuidObject::class);
        if ($user_uuid instanceof View) {
            return $this->handleView($user_uuid);
        }

        $user = $this->userRepository->findOneBy(['uuid' => $user_uuid->uuid]);
        if (empty($user)) {
            $view = $this->view(Error::withMessage('User not found'), Response::HTTP_NOT_FOUND);

            return $this->handleView($view);
        }

        if ($this->UserJoin($clan, $user)) {
            $view = $this->view(null, Response::HTTP_NO_CONTENT);
        } else {
            $view = $this->view(Error::withMessage('User already member'), Response::HTTP_OK);
        }

        return $this->handleView($view);
    }

    /**
     * Adds a User to a Clan.
     */
    #[Rest\Post('/{uuid}/admins', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function addAdminAction(string $uuid, Request $request): Response
    {
        $clan = $this->clanRepository->findOneBy(['uuid' => $uuid]);
        if (!$clan) {
            return $this->handleView($this->view(null, Response::HTTP_NOT_FOUND));
        }

        $user_uuid = $this->deserializeAndValidate($request, UuidObject::class);
        if ($user_uuid instanceof View) {
            return $this->handleView($user_uuid);
        }

        $user = $this->userRepository->findOneBy(['uuid' => $user_uuid->uuid]);
        if (empty($user)) {
            $view = $this->view(Error::withMessage('User not found'), Response::HTTP_NOT_FOUND);

            return $this->handleView($view);
        }

        if ($this->UserSetAdmin($clan, $user, true) || $this->UserJoin($clan, $user, true)) {
            $view = $this->view(null, Response::HTTP_NO_CONTENT);
        } else {
            $view = $this->view(Error::withMessage('User already admin'), Response::HTTP_OK);
        }

        return $this->handleView($view);
    }

    /**
     * Gets Users of Clan.
     */
    #[Rest\Get('/{uuid}/users', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    #[Rest\QueryParam(name: 'depth', requirements: '\d+', default: 1, allowBlank: false)]
    public function getMemberAction(string $uuid, ParamFetcher $fetcher): Response
    {
        $clan = $this->clanRepository->findOneBy(['uuid' => $uuid]);
        if (!$clan) {
            return $this->handleView($this->view(null, Response::HTTP_NOT_FOUND));
        }

        $result = [];
        foreach ($clan->getUsers() as $userClan) {
            $result[] = $userClan->getUser();
        }

        $view = $this->view($result, Response::HTTP_OK);
        $view->getContext()->setAttribute(UserClanNormalizer::DEPTH, intval($fetcher->get('depth')));

        return $this->handleView($view);
    }

    /**
     * Gets Users of Clan.
     */
    #[Rest\Get('/{uuid}/admins', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    #[Rest\QueryParam(name: 'depth', requirements: '\d+', default: 1, allowBlank: false)]
    public function getAdminAction(string $uuid, ParamFetcher $fetcher): Response
    {
        $clan = $this->clanRepository->findOneBy(['uuid' => $uuid]);
        if (!$clan) {
            return $this->handleView($this->view(null, Response::HTTP_NOT_FOUND));
        }

        $result = [];
        foreach ($clan->getUsers() as $userClan) {
            if ($userClan->getAdmin()) {
                $result[] = $userClan->getUser();
            }
        }

        $view = $this->view($result, Response::HTTP_OK);
        $view->getContext()->setAttribute(UserClanNormalizer::DEPTH, intval($fetcher->get('depth')));

        return $this->handleView($view);
    }

    /**
     * Removes a User from a Clan.
     */
    #[Rest\Delete('/{uuid}/users/{user}', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', 'user' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function removeMemberAction(string $uuid, string $user): Response
    {
        $clan = $this->clanRepository->findOneBy(['uuid' => $uuid]);
        if (!$clan) {
            return $this->handleView($this->view(null, Response::HTTP_NOT_FOUND));
        }

        $userEntity = $this->userRepository->findOneBy(['uuid' => $user]);
        if (!$userEntity) {
            return $this->handleView($this->view(Error::withMessage('User not found'), Response::HTTP_NOT_FOUND));
        }

        if ($this->UserLeave($clan, $userEntity)) {
            $view = $this->view(null, Response::HTTP_NO_CONTENT);
        } else {
            $view = $this->view(Error::withMessage('User not member'), Response::HTTP_NOT_FOUND);
        }

        return $this->handleView($view);
    }

    /**
     * Removes an Admin from a Clan.
     */
    #[Rest\Delete('/{uuid}/admins/{user}', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', 'user' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function removeAdminAction(string $uuid, string $user): Response
    {
        $clan = $this->clanRepository->findOneBy(['uuid' => $uuid]);
        if (!$clan) {
            return $this->handleView($this->view(null, Response::HTTP_NOT_FOUND));
        }

        $userEntity = $this->userRepository->findOneBy(['uuid' => $user]);
        if (!$userEntity) {
            return $this->handleView($this->view(Error::withMessage('User not found'), Response::HTTP_NOT_FOUND));
        }

        if ($this->UserSetAdmin($clan, $userEntity, false)) {
            $view = $this->view(null, Response::HTTP_NO_CONTENT);
        } else {
            $view = $this->view(Error::withMessage('User not admin'), Response::HTTP_NOT_FOUND);
        }

        return $this->handleView($view);
    }

    /**
     * Gets a User from a Clan.
     */
    #[Rest\Get('/{uuid}/users/{user}', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', 'user' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function getMemberOfClanAction(string $uuid, string $user): RedirectResponse|Response
    {
        $clan = $this->clanRepository->findOneBy(['uuid' => $uuid]);
        if (!$clan) {
            return $this->handleView($this->view(null, Response::HTTP_NOT_FOUND));
        }

        $userEntity = $this->userRepository->findOneBy(['uuid' => $user]);
        if (!$userEntity) {
            return $this->handleView($this->view(Error::withMessage('User not found'), Response::HTTP_NOT_FOUND));
        }

        $user_ids = $clan->getUsers()
            ->map(fn (UserClan $uc) => $uc->getUser()->getUuid())
            ->toArray();
        if (!in_array($userEntity->getUuid(), $user_ids)) {
            return $this->handleView($this->view(Error::withMessage('User not in clan'), Response::HTTP_NOT_FOUND));
        }

        return $this->redirectToRoute('app_rest_user_getuser', ['uuid' => $userEntity->getUuid()]);
    }

    /**
     * Gets a User from a Clan.
     */
    #[Rest\Get('/{uuid}/admins/{user}', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', 'user' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function getAdminOfClanAction(string $uuid, string $user): RedirectResponse|Response
    {
        $clan = $this->clanRepository->findOneBy(['uuid' => $uuid]);
        if (!$clan) {
            return $this->handleView($this->view(null, Response::HTTP_NOT_FOUND));
        }

        $userEntity = $this->userRepository->findOneBy(['uuid' => $user]);
        if (!$userEntity) {
            return $this->handleView($this->view(Error::withMessage('User not found'), Response::HTTP_NOT_FOUND));
        }

        $user_ids = $clan->getUsers()
            ->filter(fn (UserClan $uc) => $uc->getAdmin())
            ->map(fn (UserClan $uc) => $uc->getUser()->getUuid())
            ->toArray();
        if (!in_array($userEntity->getUuid(), $user_ids)) {
            return $this->handleView($this->view(Error::withMessage('User not admin of clan'), Response::HTTP_NOT_FOUND));
        }

        return $this->redirectToRoute('app_rest_user_getuser', ['uuid' => $userEntity->getUuid()]);
    }

    /**
     * @return bool True if user was joined, false otherwise
     */
    private function UserJoin(Clan $clan, User $user, bool $admin = false): bool
    {
        foreach ($clan->getUsers() as $userClan) {
            if ($userClan->getUser() === $user) {
                return false;
            }
        }

        $userClan = new UserClan();
        $userClan->setClan($clan);
        $userClan->setUser($user);
        $userClan->setAdmin($admin);
        $this->em->persist($userClan);
        $this->em->flush();

        return true;
    }

    /**
     * @return bool True if user was removed, false otherwise
     */
    private function UserLeave(Clan $clan, User $user): bool
    {
        foreach ($clan->getUsers() as $userClan) {
            if ($userClan->getUser() === $user) {
                $this->em->remove($userClan);
                $this->em->flush();

                return true;
            }
        }

        return false;
    }

    /**
     * @return bool True if user is member and status was changed, false otherwise
     */
    private function UserSetAdmin(Clan $clan, User $user, bool $admin): bool
    {
        foreach ($clan->getUsers() as $userClan) {
            if ($userClan->getUser() === $user) {
                if ($admin === $userClan->getAdmin()) {
                    return false;
                }
                $userClan->setAdmin($admin);
                $this->em->persist($userClan);
                $this->em->flush();

                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the Clan credentials are correct.
     *
     * Checks Username/Password against the Database and returns the user if credentials are valid
     *
     * @OA\Response(
     *     response=200,
     *     description="Returns the Clan",
     *     @OA\Schema(type="object", ref=@Model(type=\App\Entity\Clan::class, groups={"read"}))
     * )
     * @OA\Response(
     *     response=404,
     *     description="Returns if no Name and/or Password could be found"
     * )
     * @OA\RequestBody(
     *     description="credentials as JSON",
     *     required=true,
     *     @OA\Schema(type="object", format="application/json", ref=@Model(type=\App\Transfer\AuthObject::class))
     * )
     * @OA\Tag(name="Authorization")
     */
    #[Rest\Post('/authorize')]
    public function postAuthorizeAction(Request $request): Response
    {
        $auth = $this->deserializeAndValidate($request, AuthObject::class);
        if ($auth instanceof View) {
            return $this->handleView($auth);
        }

        // Check if User can log in
        $clan = $this->clanService->checkCredentials($auth->name, $auth->secret);

        if ($clan) {
            $view = $this->view($clan);
        } else {
            $view = $this->view(Error::withMessage('Invalid credentials'), Response::HTTP_NOT_FOUND);
        }

        return $this->handleView($view);
    }

    /**
     * Requests multiple clans by their uuids.
     *
     * Post a Bulk Request object to get a response object.
     *
     * @OA\Response(
     *     response=200,
     *     description="Returns the requested Clans",
     *     @OA\Schema(type="array", ref=@Model(type=\App\Entity\Clan::class, groups={"read"}))
     * )
     * @OA\Response(
     *     response=400,
     *     description="Returns the request was malformated"
     * )
     * @OA\RequestBody(
     *     description="JSON array of clan UUIDs",
     *     required=true,
     *     @OA\Schema(type="object", format="application/json", ref=@Model(type=\App\Transfer\Bulk::class))
     * )
     */
    #[Rest\Post('/bulk')]
    public function postBulkRequestAction(Request $request): Response
    {
        $bulk = $this->deserializeAndValidate($request, Bulk::class);
        if ($bulk instanceof View) {
            return $this->handleView($bulk);
        }

        $data = $this->clanRepository->findByBulk($bulk);

        return $this->handleView($this->view($data));
    }
}
