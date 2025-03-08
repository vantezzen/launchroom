import logo from '@/assets/launchroom.png';

export default function AppLogoIcon(props: React.ImgHTMLAttributes<HTMLImageElement>) {
    return <img src={logo} alt="Launchroom" {...props} />;
}
