<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\Type\UserLoginType;
use App\Form\Type\UserEmailChangeType;
use App\Form\Type\UserPasswordChangeType;
use App\Form\Type\UserRegistrationType;
use App\Service\CursorService;
use App\Service\FileService;
use App\Service\SettingsService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
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

    #[Route("/v/{customUrl}.{fileExtension}", name: "view_file")]
    public function viewFileAction(string $customUrl, string $fileExtension, FileService $fileService)
    {
        $file = $fileService->getFileForView($customUrl);
        /** @var User|null $user */
        $user = $this->getUser();
        $canManage = $file ? $fileService->canManage($user, $file) : false;
        if (!$file || ($file->markedForDeletion() && !$canManage)) {
            throw $this->createNotFoundException();
        }
        if ($file->isMimeType(['image'])) {
            $kind = 'image';
        } elseif ($file->isMimeType(['video'])) {
            $kind = 'video';
        } elseif ($file->isMimeType(['audio'])) {
            $kind = 'audio';
        } elseif ($file->isTextual()) {
            $kind = 'text';
        } else {
            $kind = 'file';
        }
        $text = null;
        $textTruncated = false;
        if ($kind === 'text' && !$file->markedForDeletion()) {
            $limit = 512 * 1024;
            $path = $fileService->buildFullFilePath($file);
            if (is_readable($path)) {
                $text = (string) file_get_contents($path, false, null, 0, $limit + 1);
                $textTruncated = strlen($text) > $limit;
                $text = mb_convert_encoding(substr($text, 0, $limit), 'UTF-8', 'UTF-8');
            }
        }
        return $this->render('view_file.html.twig', [
            'text' => $text,
            'textTruncated' => $textTruncated,
            'file' => $file,
            'kind' => $kind,
            'canManage' => $canManage,
            'size' => UserService::formatSize($file->getInternalSize()),
            'extension' => $file->getOriginalExtension() ?: 'bin'
        ]);
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
        if (!$this->getParameter('app.allow_registration')) {
            throw $this->createNotFoundException('Registration is disabled');
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User) {
            return $this->redirectToRoute('home');
        }

        $form = $this->createForm(UserRegistrationType::class, null, [
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
    public function userCabinetHomeAction(Request $request, UserService $userService, Security $security, LoggerInterface $logger, EntityManagerInterface $em)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        // Two independent forms on one page: each only submits when its own name is in the request.
        $emailForm = $this->createForm(UserEmailChangeType::class, null, [
            'entity_manager' => $em,
            'current_user' => $user
        ]);
        $emailForm->handleRequest($request);

        if ($emailForm->isSubmitted() && $emailForm->isValid()) {
            try {
                $userService->changeEmail($user, $emailForm->getData()['email']);
                // The email is the session's user identifier, so refresh the token like after a password change.
                $security->login($user, 'form_login', 'main');
                $this->addFlash('global-success', 'Email changed.');
                return $this->redirectToRoute('cabinet_home');
            } catch (\Exception $e) {
                $logger->error('Error changing email for user ' . $user->getId() . ': ' . $e->getMessage());
                $emailForm->addError(new FormError("Unexpected error has occured and has been logged, try again later or something"));
            }
        }

        $form = $this->createForm(UserPasswordChangeType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $userService->changePassword($user, $form->getData()['newPassword']);
                // The session token compares the stored password hash on every request,
                // so re-login here to keep the user signed in after the change.
                $security->login($user, 'form_login', 'main');
                $this->addFlash('global-success', 'Password changed.');
                return $this->redirectToRoute('cabinet_home');
            } catch (\Exception $e) {
                $logger->error('Error changing password for user ' . $user->getId() . ': ' . $e->getMessage());
                $form->addError(new FormError("Unexpected error has occured and has been logged, try again later or something"));
            }
        }

        return $this->render('manage_profile.html.twig', [
            'page' => 'home',
            'form' => $form->createView(),
            'emailForm' => $emailForm->createView()
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
        $pageSize = $cursorService->getPageSizeFromRequest($request);
        $pageData = $userService->getUserUploadHistoryPage($user, $cursor, $orderBy, $filter, $pageSize);
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

    #[Route("/manage/admin/anonymous/", name: "admin_manage_anonymous")]
    public function adminManageAnonymousFiles(Request $request, UserService $userService, CursorService $cursorService, FileService $fileService)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $orderBy = $cursorService->getOrderFromRequest($request);
        $filter = $cursorService->getFilterFromRequest($request);
        $cursor = $cursorService->decodeCursor($request->query->get('cursor'));
        $pageSize = $cursorService->getPageSizeFromRequest($request);
        $pageData = $userService->getAnonymousUploadHistoryPage($cursor, $orderBy, $filter, $pageSize);
        $dateTree = $userService->getAnonymousUploadDateTree();
        $removalPivot = $fileService->getDeletionPivotDate();
        return $this->render('admin_anonymous.html.twig', [
            'pageData' => $pageData,
            'dateTree' => $dateTree,
            'pivot' => $removalPivot,
            'filter' => json_encode($request->query->all())
        ]);
    }

    #[Route("/manage/admin/users/new/", name: "admin_create_user")]
    public function adminCreateUser(Request $request, EntityManagerInterface $em, UserService $userService, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(UserRegistrationType::class, null, [
            'entity_manager' => $em,
            'with_role' => true
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $newUser = $userService->createUser($form->getData());
                $this->addFlash('global-success', "User " . $newUser->getLogin() . " created.");
                return $this->redirectToRoute('admin_manage_users');
            } catch (\Exception $e) {
                $logger->error('Error creating user (admin): ' . $e->getMessage());
                $form->addError(new FormError("Unexpected error has occured and has been logged, try again later or something"));
            }
        }

        return $this->render('admin_create_user.html.twig', [
            'form' => $form->createView()
        ]);
    }

    #[Route("/manage/admin/settings/", name: "admin_settings", methods: ['GET', 'POST'])]
    public function adminSettings(Request $request, SettingsService $settings, LoggerInterface $logger)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings', (string) $request->request->get('_token'))) {
                $this->addFlash('global-danger', 'The form has expired, please try again.');
                return $this->redirectToRoute('admin_settings');
            }
            $values = [];
            foreach (array_keys(SettingsService::SETTINGS) as $key) {
                $values[$key] = $request->request->getBoolean($key);
            }
            try {
                $settings->save($values);
                $logger->info('Settings saved by admin ' . $this->getUser()->getId() . ': ' . json_encode($values));
                $this->addFlash('global-success', 'Settings saved.');
            } catch (\Exception $e) {
                $logger->error('Cannot save settings: ' . $e->getMessage());
                $this->addFlash('global-danger', 'Settings could not be written: ' . $e->getMessage());
            }
            return $this->redirectToRoute('admin_settings');
        }
        return $this->render('admin_settings.html.twig', [
            'settings' => SettingsService::SETTINGS,
            'values' => $settings->getEffectiveValues(),
            'writable' => $settings->isWritable(),
            'dumpedEnv' => $settings->hasDumpedEnv(),
            'envPath' => $settings->getLocalEnvPath(),
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
