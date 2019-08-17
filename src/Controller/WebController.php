<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;


class WebController extends AbstractController
{
    /**
     * @Route("/", name="home")
     */
    public function homeAction(Request $request)
    {
        return $this->render('homepage.html.twig');
    }

    /**
     * @Route("/login", name="auth_login")
     */
    public function loginAction(AuthenticationUtils $authenticationUtils)
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();
        $form = $this->createForm('App\Form\Type\UserLoginType');

        return $this->render('loginpage.html.twig', [
            'error' => $error,
            'lastUsername' => $lastUsername,
            'form' => $form->createView()
        ]);
    }

    /**
     * @Route("/logout", name="auth_logout")
     */
    public function logoutAction()
    {

    }

    /**
     * @Route("/register", name="auth_register")
     */
    public function registerAction(Request $request, EntityManagerInterface $em, UserService $userService, LoggerInterface $logger)
    {
        $currentUser = $this->getUser();
        if ($currentUser instanceof User) {
            return $this->redirectToRoute('home');
        }

        $form = $this->createForm('App\Form\Type\UserRegistrationType', null, [
            'entity_manager' => $em
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $newUser = $userService->createUser($form->getData());
                $this->addFlash('global-success', "User id " . $newUser->getLogin() . " created. You can log in now.");
                return $this->redirectToRoute('home');
            } catch (\Exception $e) {
                $logger->error('Error creating user: ' . $e->getMessage());
                $form->addError(new FormError("Unexpected error has occured and has been logged, try again later or something"));
            }
        }

        return $this->render('signup.html.twig', [
            'form' => $form->createView()
        ]);
    }

    /**
     * @Route("/manage/", name="cabinet_home")
     */
    public function userCabinetHomeAction(Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('manage_layout.html.twig', [
            'page' => 'home'
        ]);
    }

    /**
     * @Route("/manage/mytoken/", name="cabinet_token")
     */
    public function userCabinetViewTokenAction(Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('manage_displaytoken.html.twig', [
            'page' => 'token'
        ]);
    }

    /**
     * @Route("/manage/mypics/", name="cabinet_mypics")
     */
    public function userCabinetViewPicturesAction(Request $request, UserService $userService)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $afterOffset = $request->query->getInt('after');
        $beforeOffset = $request->query->getInt('before');
        $user = $this->getUser();
        $pageData = $userService->getUserUploadHistoryPage($user, $beforeOffset, $afterOffset);
        $dateTree = $userService->getUploadDateTree($user);
        return $this->render('manage_mypics.html.twig', [
            'page' => 'mypics',
            'pageData' => $pageData,
            'dateTree' => $dateTree
        ]);
    }
}