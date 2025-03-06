import { Coffee, Cog, Cpu, FileCheck, FileCode, FileJson, FileSpreadsheet, FileType, Gem, Hash, Scroll } from 'lucide-react';

interface LanguageIconProps {
    language: string;
}

export function LanguageIcon({ language }: LanguageIconProps) {
    const iconProps = { className: 'w-5 h-5' };

    switch (language?.toLowerCase()) {
        case 'javascript':
            return <FileJson {...iconProps} className="text-yellow-500" />;
        case 'typescript':
            return <FileCode {...iconProps} className="text-blue-500" />;
        case 'python':
            return <FileType {...iconProps} className="text-green-500" />;
        case 'java':
            return <Coffee {...iconProps} className="text-red-500" />;
        case 'c#':
            return <Hash {...iconProps} className="text-purple-500" />;
        case 'go':
            return <FileCheck {...iconProps} className="text-cyan-500" />;
        case 'rust':
            return <Cpu {...iconProps} className="text-orange-500" />;
        case 'php':
            return <FileSpreadsheet {...iconProps} className="text-indigo-500" />;
        case 'ruby':
            return <Gem {...iconProps} className="text-red-600" />;
        case 'c++':
            return <Cog {...iconProps} className="text-blue-600" />;
        default:
            return <Scroll {...iconProps} className="text-gray-500" />;
    }
}
