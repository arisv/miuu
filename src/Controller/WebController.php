<?php

namespace App\Controller;

use App\Service\PurgeNotCancellable;
use App\Service\PurgeService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Entity\User;
use App\Form\Type\UserLoginType;
use App\Form\Type\UserEmailChangeType;
use App\Form\Type\UserPasswordChangeType;
use App\Form\Type\UserRegistrationType;
use App\Service\CursorService;
use App\Service\DeviceTokenService;
use App\Service\FileService;
use App\Service\SettingsService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;


class WebController extends AbstractController
{
    #[Route("/", name: "home")]
    public function homeAction(Request $request)
    {
        return $this->render('homepage.html.twig');
    }

    /** Served by a route (not a static file) so paths, colours and the share target stay in one place. */
    #[Route("/manifest.webmanifest", name: "pwa_manifest", priority: 10)]
    public function manifestAction()
    {
        $manifest = [
            'name' => 'MIU',
            'short_name' => 'MIU',
            'description' => 'Upload and share files',
            'id' => '/',
            'start_url' => $this->generateUrl('home'),
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#131715',
            'theme_color' => '#34e6a1',
            'icons' => [
                ['src' => '/static/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/static/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/static/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            // Android share sheet: files and links shared to the installed app land on the share endpoint.
            'share_target' => [
                'action' => $this->generateUrl('share_target'),
                'method' => 'POST',
                'enctype' => 'multipart/form-data',
                'params' => [
                    'title' => 'title',
                    'text' => 'text',
                    'url' => 'url',
                    'files' => [
                        // Extensions as well as MIME types: some Android gallery apps hand Chrome a null or
                        // generic type for scoped-storage URIs, and Chrome silently drops files that match nothing.
                        ['name' => 'meowfile', 'accept' => [
                            'image/*', 'video/*', 'audio/*', 'application/*', 'text/*', 'application/octet-stream',
                            '.jpg', '.jpeg', '.png', '.gif', '.webp', '.heic', '.heif', '.avif', '.bmp', '.svg',
                            '.mp4', '.mov', '.webm', '.mkv', '.m4v', '.3gp',
                            '.mp3', '.m4a', '.aac', '.ogg', '.opus', '.wav', '.flac',
                            '.pdf', '.zip', '.7z', '.rar', '.txt', '.md', '.json', '.csv', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.apk',
                        ]],
                    ],
                ],
            ],
        ];
        $response = new JsonResponse($manifest);
        $response->headers->set('Content-Type', 'application/manifest+json');
        $response->setPublic();
        $response->setMaxAge(3600);
        return $response;
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
        $kind = $file->previewKind();
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
        $imageSize = null;
        if ($kind === 'image' && !$file->markedForDeletion()) {
            $dims = @getimagesize($fileService->buildFullFilePath($file));
            if ($dims) {
                $imageSize = ['width' => $dims[0], 'height' => $dims[1]];
            }
        }
        return $this->render('view_file.html.twig', [
            'text' => $text,
            'textTruncated' => $textTruncated,
            'imageSize' => $imageSize,
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
    public function userCabinetHomeAction(Request $request, UserService $userService, Security $security, LoggerInterface $logger, EntityManagerInterface $em, PurgeService $purge)
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
            'emailForm' => $emailForm->createView(),
            'purge' => $purge->status($user),
        ]);
    }

    /** "Delete all my files": schedules the purge after the password is confirmed. */
    #[Route("/manage/purge/", name: "cabinet_purge", methods: ['POST'])]
    public function userPurgeAction(Request $request, PurgeService $purge, UserPasswordHasherInterface $hasher)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('purge_files', (string) $request->request->get('_token'))) {
            $this->addFlash('global-danger', 'The form has expired, please try again.');
            return $this->redirectToRoute('cabinet_home');
        }
        $password = (string) $request->request->get('password', '');
        if ($password === '' || !$hasher->isPasswordValid($user, $password)) {
            $this->addFlash('global-danger', 'Password is incorrect; nothing was deleted.');
            return $this->redirectToRoute('cabinet_home');
        }
        $status = $purge->purge($user, 'the user (web)');
        $this->addFlash('global-success', sprintf(
            '%d %s will be deleted at %s. You can cancel until then.',
            $status['total'], $status['total'] === 1 ? 'file' : 'files', $user->purgeDueAt()->format('H:i')
        ));
        return $this->redirectToRoute('cabinet_home');
    }

    #[Route("/manage/purge/cancel/", name: "cabinet_purge_cancel", methods: ['POST'])]
    public function userPurgeCancelAction(Request $request, PurgeService $purge)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('purge_files', (string) $request->request->get('_token'))) {
            $this->addFlash('global-danger', 'The form has expired, please try again.');
            return $this->redirectToRoute('cabinet_home');
        }
        try {
            $purge->cancel($user, 'the user (web)');
            $this->addFlash('global-success', 'Deletion cancelled; your files are back.');
        } catch (PurgeNotCancellable $e) {
            $this->addFlash('global-danger', $e->getMessage());
        }
        return $this->redirectToRoute('cabinet_home');
    }

    #[Route("/manage/mytoken/", name: "cabinet_token")]
    public function userCabinetViewTokenAction(Request $request, DeviceTokenService $deviceTokens)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();
        return $this->render('manage_displaytoken.html.twig', [
            'page' => 'token',
            'devices' => $deviceTokens->listActive($user),
        ]);
    }

    #[Route("/manage/mypics/", name: "cabinet_mypics")]
    public function userCabinetViewPicturesAction(Request $request, UserService $userService, CursorService $cursorService, FileService $fileService, PurgeService $purge)
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
            'ordering' => $orderBy->toArray(),
            'totalCount' => $userService->countUserUploadHistory($user, $filter),
            'purge' => $purge->status($user),
            'filter' => json_encode($request->query->all() + $orderBy->toArray())
        ]);
    }

    #[Route("/manage/admin/users/", name: "admin_manage_users")]
    public function adminManageUsers(Request $request, UserService $userService, PurgeService $purge)
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $userData = $userService->getAllUserIndex();
        return $this->render('admin_users.html.twig', [
            'userlist' => $userData,
            'purgeStates' => $purge->statusForAll(),
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
            'ordering' => $orderBy->toArray(),
            'totalCount' => $userService->countAnonymousUploadHistory($filter),
            'filter' => json_encode($request->query->all() + $orderBy->toArray())
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
