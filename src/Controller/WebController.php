<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\Type\UserLoginType;
use App\Service\CursorService;
use App\Service\FileService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;


class WebController extends AbstractController
{
    #[Route("/", name: "home")]
    public function homeAction(Request $request)
    {
        return $this->render('homepage.html.twig');
    }

    #[Route("/login", name: "auth_login")]
    public function loginAction(AuthenticationUtils $authenticationUtils)
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();
        $form = $this->createForm(UserLoginType::class);

        return $this->render('loginpage.html.twig', [
            'error' => $error,
            'lastUsername' => $lastUsername,
            'form' => $form->createView()
        ]);
    }

    #[Route("/logout", name: "auth_logout")]
    public function logoutAction()
    {
    }

    #[Route("/register", name: "auth_register")]
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

    #[Route("/manage/", name: "cabinet_home")]
    public function userCabinetHomeAction(Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('manage_layout.html.twig', [
            'page' => 'home'
        ]);
    }

    #[Route("/manage/mytoken/", name: "cabinet_token")]
    public function userCabinetViewTokenAction(Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('manage_displaytoken.html.twig', [
            'page' => 'token'
        ]);
    }

    #[Route("/manage/mypics/", name: "cabinet_mypics")]
    public function userCabinetViewPicturesAction(Request $request, UserService $userService, CursorService $cursorService, FileService $fileService)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();
        $orderBy = $cursorService->getOrderFromRequest($request);
        $filter = $cursorService->getFilterFromRequest($request);
        $cursor = $cursorService->decodeCursor($request->query->get('cursor'));
        $pageData = $userService->getUserUploadHistoryPage($user, $cursor, $orderBy, $filter);
        $dateTree = $userService->getUploadDateTree($user);
        $removalPivot = $fileService->getDeletionPivotDate();
        return $this->render('manage_mypics.html.twig', [
            'page' => 'mypics',
            'pageData' => $pageData,
            'dateTree' => $dateTree,
            'pivot' => $removalPivot,
            'filter' => json_encode($request->query->all())
        ]);
    }

    #[Route("/manage/admin/users/", name: "admin_manage_users")]
    public function adminManageUsers(Request $request, UserService $userService)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $userData = $userService->getAllUserIndex();
        return $this->render('admin_users.html.twig', [
            'userlist' => $userData
        ]);
    }

    #[Route("/manage/admin/files/", name: "admin_manage_files")]
    public function adminManageFiles(Request $request, FileService $fileService)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        [$files, $users] = $fileService->getAllFilesBySize();
        return $this->render('admin_files.html.twig', [
            'files' => $files,
            'users' => $users
        ]);
    }
}
