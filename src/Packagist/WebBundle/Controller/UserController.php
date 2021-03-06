<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packagist\WebBundle\Controller;

use Doctrine\ORM\NoResultException;
use FOS\UserBundle\Model\UserInterface;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\SshCredentials;
use Packagist\WebBundle\Entity\User;
use Packagist\WebBundle\Form\Type\CustomerUserType;
use Packagist\WebBundle\Form\Type\SshKeyCredentialType;
use Packagist\WebBundle\Model\RedisAdapter;
use Packagist\WebBundle\Util\SshKeyHelper;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UserController extends Controller
{
    /**
     * @Template()
     * @Route("/users/", name="users_list")
     *
     * @param Request $request
     * @return array
     */
    public function listAction(Request $request)
    {
        $page = $request->query->get('page', 1);

        $qb = $this->getDoctrine()->getRepository('PackagistWebBundle:User')
            ->createQueryBuilder('u');
        $qb->where("u.roles NOT LIKE '%ADMIN%'")
            ->orderBy('u.id', 'DESC');

        $paginator = new Pagerfanta(new DoctrineORMAdapter($qb, false));
        $paginator->setMaxPerPage(6);

        $csrfForm = $this->createFormBuilder([])->getForm();

        $paginator->setCurrentPage($page, false, true);
        return [
            'users' => $paginator,
            'csrfForm' => $csrfForm
        ];
    }

    /**
     * @Template()
     * @Route("/users/{name}/update", name="users_update")
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     *
     * @param User $user
     * @param  Request $request
     * @return mixed
     */
    public function updateAction(Request $request, User $user)
    {
        $token = $this->get('security.token_storage')->getToken();
        if ($token && $token->getUsername() !== $user->getUsername() && !$user->isAdmin()) {
            return $this->handleUpdate($request, $user, 'User has been saved.');
        }

        throw new AccessDeniedHttpException('You can not update yourself');
    }

    /**
     * @Template("PackagistWebBundle:User:update.html.twig")
     * @Route("/users/create", name="users_create")
     *
     * @param Request $request
     * @return mixed
     */
    public function createAction(Request $request)
    {
        $user = new User();
        $user->generateApiToken();
        return $this->handleUpdate($request, $user, 'User has been saved.');
    }

    protected function handleUpdate(Request $request, User $user, $flashMessage)
    {
        $form = $this->createForm(CustomerUserType::class, $user);
        if ($request->getMethod() === 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $user = $form->getData();
                $this->get('fos_user.user_manager')->updateUser($user);

                $this->addFlash('success', $flashMessage);
                return new RedirectResponse($this->generateUrl('users_list'));
            }
        }

        return [
            'form' => $form->createView(),
            'entity' => $user
        ];
    }

    /**
     * @Template()
     * @Route("/users/{name}/packages/", name="user_packages")
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function packagesAction(Request $req, User $user)
    {
        $packages = $this->getUserPackages($req, $user);

        return [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'user' => $user,
        ];
    }

    /**
     * @param Request $req
     * @return Response
     */
    public function myProfileAction(Request $req)
    {
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if (!is_object($user) || !$user instanceof UserInterface) {
            throw new AccessDeniedException('This user does not have access to this section.');
        }

        $packages = $this->getUserPackages($req, $user);

        return $this->container->get('templating')->renderResponse(
            'FOSUserBundle:Profile:show.html.twig',
            [
                'packages' => $packages,
                'meta' => $this->getPackagesMetadata($packages),
                'user' => $user,
            ]
        );
    }

    /**
     * @Template("PackagistWebBundle:User:sshkey.html.twig")
     * @Route("/users/sshkey", name="user_add_sshkey")
     * {@inheritdoc}
     */
    public function addSSHKeyAction(Request $request)
    {
        $sshKey = new SshCredentials();
        $form = $this->createForm(SshKeyCredentialType::class, $sshKey);

        if ($request->getMethod() === 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $sshKey = $form->getData();
                $em = $this->getDoctrine()->getManager();
                $fingerprint = SshKeyHelper::getFingerprint($sshKey->getKey());
                $sshKey->setFingerprint($fingerprint);

                $em->persist($sshKey);
                $em->flush();

                $this->addFlash('success', 'Ssh key was added successfully');
                return new RedirectResponse('/');
            }
        }

        return [
            'form' => $form->createView(),
        ];
    }

    /**
     * @Template()
     * @Route("/users/{name}/", name="user_profile")
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function profileAction(Request $req, User $user)
    {
        $deleteForm = $this->createFormBuilder([])->getForm();
        $packages = $this->getUserPackages($req, $user);

        $data = [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'user' => $user,
            'deleteForm' => $deleteForm->createView()
        ];

        return $data;
    }

    /**
     * @Route("/users/{name}/delete", name="user_delete")
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function deleteAction(Request $request, User $user)
    {
        $form = $this->createFormBuilder([])->getForm();
        $form->submit($request->request->get('form'));
        if ($form->isValid()) {
            $request->getSession()->save();
            $em = $this->getDoctrine()->getManager();
            $em->remove($user);
            $em->flush();

            return new RedirectResponse('/');
        }

        return new Response('Invalid form input', 400);
    }

    /**
     * @Template()
     * @Route("/users/{name}/favorites/", name="user_favorites")
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     * @Method({"GET"})
     */
    public function favoritesAction(Request $req, User $user)
    {
        try {
            if (!$this->get('snc_redis.default')->isConnected()) {
                $this->get('snc_redis.default')->connect();
            }
        } catch (\Exception $e) {
            $this->get('session')->getFlashBag()->set('error', 'Could not connect to the Redis database.');
            $this->get('logger')->notice($e->getMessage(), ['exception' => $e]);

            return ['user' => $user, 'packages' => []];
        }

        $paginator = new Pagerfanta(
            new RedisAdapter($this->get('packagist.favorite_manager'), $user, 'getFavorites', 'getFavoriteCount')
        );

        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($req->query->get('page', 1), false, true);

        return ['packages' => $paginator, 'user' => $user];
    }

    /**
     * @Route("/users/{name}/favorites/", name="user_add_fav", defaults={"_format" = "json"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     * @Method({"POST"})
     */
    public function postFavoriteAction(Request $req, User $user)
    {
        if ($user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You can only change your own favorites');
        }

        $package = $req->request->get('package');
        try {
            $package = $this->getDoctrine()
                ->getRepository('PackagistWebBundle:Package')
                ->findOneByName($package);
        } catch (NoResultException $e) {
            throw new NotFoundHttpException('The given package "'.$package.'" was not found.');
        }

        $this->get('packagist.favorite_manager')->markFavorite($user, $package);

        return new Response('{"status": "success"}', 201);
    }

    /**
     * @Route("/users/{name}/favorites/{package}", name="user_remove_fav", defaults={"_format" = "json"}, requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     * @ParamConverter("package", options={"mapping": {"package": "name"}})
     * @Method({"DELETE"})
     */
    public function deleteFavoriteAction(User $user, Package $package)
    {
        if ($user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You can only change your own favorites');
        }

        $this->get('packagist.favorite_manager')->removeFavorite($user, $package);

        return new Response('{"status": "success"}', 204);
    }

    /**
     * @param Request $req
     * @param User $user
     * @return Pagerfanta
     */
    protected function getUserPackages($req, $user)
    {
        $packages = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->getFilteredQueryBuilder(['maintainer' => $user->getId()], true);

        $paginator = new Pagerfanta(new DoctrineORMAdapter($packages, true));
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($req->query->get('page', 1), false, true);

        return $paginator;
    }
}
